<?php

namespace Illuminate\Tests\Integration\Database\EloquentMultiDimensionalArrayEagerLoadingTest;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Tests\Integration\Database\DatabaseTestCase;

// I'm not smart enough to merge selects, so the following combinations of old
// and new syntax are not supported. That being said, they aren't supported in
// the current dot notation style either
// e.g. User::with('post:id')->with('post:user_id')
// does not merge both attribute selections, so it is still consistent with
// the current approach.
//
// User::query()
//     ->with([
//         'posts.comments' => fn ($q) => $q->addSelect(['id']),
//         'posts' => fn ($query) => $query->with([
//             'comments' => function ($q) {
//                 $q->addSelect(['post_id']);
//             }]),
//     ])->get();
//
// User::query()
//     ->with([
//         'posts' => fn ($query) => $query->with([
//             'comments:post_id,title'
//         ]),
//         'posts.comments:id',
//     ])
//     ->get();
class EloquentMultiDimensionalArrayEagerLoadingTest extends DatabaseTestCase
{
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('avatars', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('content');
            $table->unsignedInteger('user_id');
        });

        Schema::create('images', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('post_id');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('content');
            $table->unsignedInteger('post_id');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('comment_id');
        });

        $user = User::create();
        $user->avatar()->create();
        $posts = $user->posts()->createMany([
            [
                'title' => '1. post title',
                'content' => '1. post content',
            ],
            [
                'title' => '2. post title',
                'content' => '2. post content',
            ],
        ]);
        $posts->map->image()->each->create();
        $comments = $posts->map->comments()->map->create([
            'title' => 'comment title',
            'content' => 'comment content',
        ]);
        $comments->map->tags()->each->create();
        $comments->map->tags()->each->create();
        $comments->map->tags()->each->create();
    }

    public function testItCanEagerLoad()
    {
        DB::enableQueryLog();

        $users = User::query()
            ->with([
                'avatar',
                'posts' => [
                    'comments' => [
                        'tags',
                    ],
                    'image',
                ],
            ])->get();

        $this->assertCount(6, DB::getQueryLog());
        $this->assertCount(1, $users);
        $this->assertTrue($users[0]->relationLoaded('avatar'));
        $this->assertNotNull($users[0]->avatar);
        $this->assertTrue($users[0]->relationLoaded('posts'));
        $this->assertCount(2, $users[0]->posts);
        $this->assertTrue($users[0]->posts[0]->isNot($users[0]->posts[1]));
        $this->assertTrue($users[0]->posts->every->relationLoaded('image'));
        $this->assertCount(2, $users[0]->posts->map->image);
        $this->assertTrue($users[0]->posts[0]->image->isNot($users[0]->posts[1]->image));
        $this->assertTrue($users[0]->posts->every->relationLoaded('comments'));
        $this->assertCount(2, $users[0]->posts->flatMap->comments);
        $this->assertTrue($users[0]->posts[0]->comments[0]->isNot($users[0]->posts[1]->comments[0]));
        $this->assertTrue($users[0]->posts->flatMap->comments->every->relationLoaded('tags'));
        $this->assertCount(6, $users[0]->posts->flatMap->comments->flatMap->tags);
    }

    public function testItAppliesConstaintsViaClosuresAndCanContinueEagerLoading()
    {
        DB::enableQueryLog();

        $users = User::query()
            ->with([
                'posts' => fn ($query) => $query->withCount('comments')->with([
                    'comments' => [
                        'tags',
                    ],
                ]),
            ])
            ->get();

        $this->assertCount(4, DB::getQueryLog());
        $this->assertCount(1, $users);
        $this->assertTrue($users[0]->relationLoaded('posts'));
        $this->assertCount(2, $users[0]->posts);
        $this->assertTrue($users[0]->posts->every(fn ($post) => $post->comments_count === 1));
        $this->assertTrue($users[0]->posts->every->relationLoaded('comments'));
        $this->assertCount(2, $users[0]->posts->flatMap->comments);
        $this->assertTrue($users[0]->posts->flatMap->comments->every->relationLoaded('tags'));
    }


    public function testItCanSpecifyAttributesToSelectInKeys()
    {
        DB::enableQueryLog();

        $users = User::query()
            ->with([
                'posts:id,title,user_id' => [
                    'comments:id,content,post_id' => [
                        'tags',
                    ],
                ],
            ])
            ->get();

        $this->assertCount(4, DB::getQueryLog());
        $this->assertCount(1, $users);
        $this->assertTrue($users[0]->relationLoaded('posts'));
        $this->assertCount(2, $users[0]->posts);
        $this->assertTrue($users[0]->posts->every(fn ($post) => array_keys($post->getAttributes()) === ['id', 'title', 'user_id']));
        $this->assertTrue($users[0]->posts->every->relationLoaded('comments'));
        $this->assertCount(2, $users[0]->posts->flatMap->comments);
        $this->assertTrue($users[0]->posts->flatMap->comments->every(fn ($post) => array_keys($post->getAttributes()) === ['id', 'content', 'post_id']));
        $this->assertTrue($users[0]->posts->flatMap->comments->every->relationLoaded('tags'));
        $this->assertCount(6, $users[0]->posts->flatMap->comments->flatMap->tags);
    }

    public function testItMixesWithDotNotation()
    {
        DB::enableQueryLog();

        $users = User::query()
            ->with([
                'posts' => [
                    'comments',
                ],
                'posts.image',
            ])
            ->get();

        $this->assertCount(4, DB::getQueryLog());
        $this->assertCount(1, $users);
        $this->assertTrue($users[0]->relationLoaded('posts'));
        $this->assertCount(2, $users[0]->posts);
        $this->assertTrue($users[0]->posts->every->relationLoaded('comments'));
        $this->assertCount(2, $users[0]->posts->flatMap->comments);
        $this->assertTrue($users[0]->posts->every->relationLoaded('image'));
        $this->assertCount(2, $users[0]->posts->map->image);
    }

    public function testItMixesConstraintsFromDotNotation()
    {
        DB::enableQueryLog();

        $users = User::query()
            ->with([
                'posts.comments' => fn ($query) => $query->with('tags'),
                'posts:id,title,user_id' => [
                    'comments' => fn ($query) => $query->withCount('tags'),
                ],
            ])
            ->get();

        $this->assertCount(4, DB::getQueryLog());
        $this->assertCount(1, $users);
        $this->assertTrue($users[0]->relationLoaded('posts'));
        $this->assertCount(2, $users[0]->posts);
        $this->assertTrue($users[0]->posts->every(fn ($post) => $post->content === null));
        $this->assertTrue($users[0]->posts->every->relationLoaded('comments'));
        $this->assertCount(2, $users[0]->posts->flatMap->comments);
        $this->assertTrue($users[0]->posts->flatMap->comments->every(fn ($comment) => $comment->tags_count === 3));
        $this->assertTrue($users[0]->posts->flatMap->comments->every->relationLoaded('tags'));
        $this->assertCount(6, $users[0]->posts->flatMap->comments->flatMap->tags);
    }
}

class User extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function avatar()
    {
        return $this->hasOne(Avatar::class);
    }
}

class Post extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function image()
    {
        return $this->hasOne(Image::class);
    }
}

class Image extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}

class Comment extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function tags()
    {
        return $this->hasMany(Tag::class);
    }
}

class Tag extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

class Avatar extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
