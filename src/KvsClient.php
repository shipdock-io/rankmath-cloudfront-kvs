<?php

namespace RankMathCloudFrontKvs;

use Aws\CloudFrontKeyValueStore\CloudFrontKeyValueStoreClient;

final class KvsClient
{
    private CloudFrontKeyValueStoreClient $client;

    public function __construct(private string $arn)
    {
        $this->client = new CloudFrontKeyValueStoreClient([
          'region' => 'us-east-1',
          'version' => 'latest'
        ]);
    }

    public function etag(): string
    {
        return $this->client->describeKeyValueStore(['KvsARN' => $this->arn])['ETag'];
    }

    /**
     * @param array<string, string> $keys
     * @return string The store's new ETag.
     */
    public function putKeys(string $etag, array $keys): string
    {
        $puts = [];
        foreach ($keys as $key => $value) {
            $puts[] = ['Key' => (string) $key, 'Value' => $value];
        }

        return $this->client->updateKeys([
            'KvsARN' => $this->arn,
            'IfMatch' => $etag,
            'Puts' => $puts,
        ])['ETag'];
    }
}
