<?php

use Kalax2\Flysystem\Obs\ObsAdapter;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;

class ObsAdapterTest extends FilesystemAdapterTestCase
{

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        $accessKey = getenv('OBS_ACCESS_KEY');
        $secretKey = getenv('OBS_SECRET_KEY');
        $region = getenv('OBS_REGION');
        $bucket = getenv('OBS_BUCKET');

        if (!$accessKey || !$secretKey || !$region || !$bucket) {
            self::markTestSkipped('Please provide OBS_ACCESS_KEY, OBS_SECRET_KEY, OBS_REGION, OBS_BUCKET environment variables');
        }

        return new ObsAdapter($accessKey, $secretKey, $region, $bucket);
    }

    /**
     * @test
     */
    public function listing_contents_recursive(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $adapter->createDirectory('path', new Config());
            $adapter->write('path/file.txt', 'string', new Config());

            $listing = $adapter->listContents('', true);
            /** @var StorageAttributes[] $items */
            $items = iterator_to_array($listing, false);
            $this->assertCount(2, $items, $this->formatIncorrectListingCount($items));
        });
    }

    public function fetching_file_size_of_a_directory(): void
    {
    }

    public function fetching_unknown_mime_type_of_a_file(): void
    {
    }
}