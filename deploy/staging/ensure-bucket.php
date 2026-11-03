<?php

declare(strict_types=1);

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$client = new S3Client([
    'version' => 'latest',
    'region' => getenv('AWS_DEFAULT_REGION') ?: 'us-east-1',
    'endpoint' => getenv('AWS_ENDPOINT'),
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key' => getenv('AWS_ACCESS_KEY_ID'),
        'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
    ],
]);

$bucket = (string) getenv('AWS_BUCKET');

for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
        $client->headBucket(['Bucket' => $bucket]);
        exit(0);
    } catch (AwsException $exception) {
        try {
            $client->createBucket(['Bucket' => $bucket]);
            exit(0);
        } catch (AwsException) {
            sleep(2);
        }
    }
}

fwrite(STDERR, "Unable to create staging evidence bucket.\n");
exit(1);
