queued=$(grep "\"queued\"" output.log | wc -l | xargs)
started=$(grep "\"started\"" output.log | wc -l | xargs)
released=$(grep "\"released\"" output.log | wc -l | xargs)
processed=$(grep "\"processed\"" output.log | wc -l | xargs)
failed=$(grep "\"failed\"" output.log | wc -l | xargs)

echo "queued ${queued}"
echo "started: ${started}"
echo "released: ${released}"
echo "processed: ${processed}"
echo "failed: ${failed}"

echo "queued - processed - failed = $((queued-processed-failed))"
echo "started - released - processed - failed = $((started-released-processed-failed))"
