<?php

namespace Kalax2\Flysystem\Obs;

use DateTimeInterface;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use Kalax2\Obs\Exception\ObsException;
use Kalax2\Obs\ObsClient;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Psr\Http\Message\StreamInterface;
use Throwable;

class ObsAdapter implements FilesystemAdapter, TemporaryUrlGenerator, ChecksumProvider
{
    private Config $config;
    private ObsClient $obsClient;
    private MimeTypeDetector $mimeTypeDetector;

    public function __construct(private string $accessKey, private string $accessSecret, private string $region, private string $bucket, array $config = [])
    {
        $this->config = new Config($config);

        $this->obsClient = new ObsClient($this->accessKey, $this->accessSecret, $this->region, $this->bucket, $this->config->get('guzzle', []));
        $this->mimeTypeDetector = new FinfoMimeTypeDetector();
    }

    /**
     * @inheritDoc
     */
    public function fileExists(string $path): bool
    {
        try {
            $this->obsClient->headObject($path);
        } catch (Throwable $e) {
            if ($e instanceof ObsException) {
                if ($e->getResponse()->getStatusCode() === 404) {
                    return false;
                }
            }

            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function directoryExists(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        $pathArray = explode('/', $path);
        array_pop($pathArray);
        $newPath = implode('/', $pathArray);
        $path = $path . '/';
        try {
            $response = $this->obsClient->listObjects(['prefix' => $newPath, 'delimiter' => '/']);
            $result = $response->getResult();
            if (array_key_exists('CommonPrefixes', $result)) {
                foreach ($result['CommonPrefixes'] as $prefix) {
                    if ($prefix['Prefix'] === $path) {
                        return true;
                    }
                }
            }
        } catch (Throwable $e) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $e);
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->putObject($path, $contents, $config);
    }

    /**
     * @inheritDoc
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->putObject($path, $contents, $config);
    }

    /**
     * @inheritDoc
     */
    public function read(string $path): string
    {
        return $this->getObject($path)->getContents();
    }

    /**
     * @inheritDoc
     */
    public function readStream(string $path)
    {
        return $this->getObject($path)->detach();
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): void
    {
        try {
            $this->obsClient->deleteObject($path);
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteDirectory(string $path): void
    {
        $counter = 1;
        $delete = [
            'Quiet' => 'false',
            'Object' => [
                ['Key' => $path . '/']
            ]
        ];

        try {
            foreach ($this->listContents($path, true) as $item) {
                $delete['Object'][] = ['Key' => $item->path() . ($item->isDir() ? '/' : '')];
                $counter++;

                if ($counter === 1000) {
                    $this->obsClient->deleteObjects($delete);
                    $counter = 0;
                    $delete['Object'] = [];
                }
            }

            if ($counter > 0) {
                $this->obsClient->deleteObjects($delete);
            }
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function createDirectory(string $path, Config $config): void
    {
        $path = str_ends_with('/', $path) ? $path : $path . '/';
        $headers = $config->get('headers', []);

        try {
            $this->obsClient->putObject($path, null, $headers);
        } catch (Throwable $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function setVisibility(string $path, string $visibility): void
    {
        try {
            $response = $this->obsClient->getObjectAcl($path);
            $acl = [
                'Owner' => $response['Owner'],
                'Delivered' => $response['Delivered'],
                'AccessControlList' => ['Grant' => []]
            ];

            $permissions = [];
            foreach ($response['AccessControlList']['Grant'] as $grant) {
                if (array_key_exists('Canned', $grant['Grantee'])) {
                    $permissions[] = $grant['Permission'];
                } else {
                    $acl['AccessControlList']['Grant'][] = $grant;
                }
            }

            if ($visibility === Visibility::PUBLIC) {
                $permissions[] = 'READ';
                $permissions = array_unique($permissions);

                if (in_array('FULL_CONTROL', $permissions) || count($permissions) === 3) {
                    $permissions = ['FULL_CONTROL'];
                }
            } else {
                if (in_array('FULL_CONTROL', $permissions)) {
                    $permissions = ['READ_ACP', 'WRITE_ACP'];
                } else {
                    $permissions = array_diff($permissions, ['READ']);
                }
            }

            foreach ($permissions as $permission) {
                $acl['AccessControlList']['Grant'][] = [
                    'Grantee' => [
                        'Canned' => 'Everyone'
                    ],
                    'Permission' => $permission
                ];
            }

            $this->obsClient->setObjectAcl($path, $acl);
        } catch (Throwable $e) {
            throw UnableToSetVisibility::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function visibility(string $path): FileAttributes
    {
        try {
            $visibility = Visibility::PRIVATE;
            $response = $this->obsClient->getObjectAcl($path);

            foreach ($response['AccessControlList']['Grant'] as $grant) {
                if (array_key_exists('Canned', $grant['Grantee']) && in_array($grant['Permission'], ['READ', 'FULL_CONTROL'])) {
                    $visibility = Visibility::PUBLIC;
                    break;
                }
            }
            return new FileAttributes($path, visibility: $visibility);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function mimeType(string $path): FileAttributes
    {
        try {
            return $this->getObjectMetadata($path);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function lastModified(string $path): FileAttributes
    {
        try {
            return $this->getObjectMetadata($path);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function fileSize(string $path): FileAttributes
    {
        try {
            return $this->getObjectMetadata($path);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $directories = [];
        $path = rtrim($path, '/');
        $path = empty($path) ? '' : $path . '/';
        $isTruncated = true;
        $marker = null;
        try {
            while ($isTruncated) {
                $response = $this->obsClient->listObjects([
                    'prefix' => $path,
                    'delimiter' => '/',
                    'marker' => $marker
                ]);

                $result = $response->getResult();
                $isTruncated = $result['IsTruncated'] === 'true';
                if ($isTruncated) {
                    $marker = $result['NextMarker'];
                }

                if (array_key_exists('Contents', $result)) {
                    foreach ($result['Contents'] as $file) {
                        if ($file['Key'] === $path) {
                            continue;
                        }
                        yield new FileAttributes(
                            path: $file['Key'],
                            fileSize: intval($file['Size']),
                            lastModified: strtotime($file['LastModified']),
                            extraMetadata: [
                                'ETag' => trim($file['ETag'], '"')
                            ]
                        );
                    }
                }

                if (array_key_exists('CommonPrefixes', $result)) {
                    foreach ($result['CommonPrefixes'] as $prefix) {
                        $directories[] = $prefix['Prefix'];
                        yield new DirectoryAttributes($prefix['Prefix']);
                    }
                }
            }
        } catch (Throwable $e) {
            throw UnableToListContents::atLocation($path, $e->getMessage(), $e);
        }

        if ($deep) {
            foreach ($directories as $directory) {
                yield from $this->listContents($directory, true);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (Throwable $e) {
            if ($e instanceof UnableToCopyFile) {
                throw UnableToMoveFile::fromLocationTo($source, $destination, $e->getPrevious());
            }
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $headers = $config->get('headers', []);
        $headers['x-obs-copy-source'] = '/' . $this->bucket . '/' . ltrim($source, '/');

        try {
            $response = $this->obsClient->getObjectAcl($source);
            $acl = [
                'Owner' => $response['Owner'],
                'Delivered' => $response['Delivered'],
                'AccessControlList' => $response['AccessControlList']
            ];

            $this->obsClient->copyObject($destination, $headers);
            $this->obsClient->setObjectAcl($destination, $acl);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string
    {
        $url = $this->obsClient->createTemporaryUrl($path, $expiresAt->getTimestamp(), $this->config->get('domain', ''));
        if ($this->config->get('ssl', true) === false) {
            $url = str_replace('https://', 'http://', $url);
        }

        return $url;
    }

    /**
     * @inheritDoc
     */
    public function checksum(string $path, Config $config): string
    {
        try {
            $fileAttributes = $this->getObjectMetadata($path);
            return $fileAttributes->extraMetadata()['ETag'];
        } catch (Throwable $e) {
            throw new UnableToProvideChecksum($e->getMessage(), $path, $e);
        }
    }

    /**
     * @param string $path
     * @return string
     */
    public function getUrl(string $path): string
    {
        $url = $this->config->get('ssl', true) ? 'https://' : 'http://';
        $url .= $this->config->get('domain') ?? $this->bucket . '.obs.' . $this->region . '.myhuaweicloud.com';
        $url .= '/' . ltrim($path, '/');

        return $url;
    }

    /**
     * @param $path
     * @return StreamInterface
     */
    private function getObject($path): StreamInterface
    {
        try {
            $response = $this->obsClient->getObject($path);
            return $response->getBody();
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * @param $path
     * @return FileAttributes
     * @throws ObsException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getObjectMetadata($path): FileAttributes
    {
        $response = $this->obsClient->headObject($path);

        $mimeType = empty($response->getHeaderLine('Content-Type')) ? null : $response->getHeaderLine('Content-Type');
        $fileSize = intval($response->getHeaderLine('Content-Length'));
        $lastModified = empty($response->getHeaderLine('Last-Modified')) ? null : strtotime($response->getHeaderLine('Last-Modified'));
        $extraMetadata = [
            'ETag' => empty($response->getHeaderLine('ETag')) ? null : trim($response->getHeaderLine('ETag'), '"')
        ];

        return new FileAttributes(
            path: $path,
            fileSize: $fileSize,
            lastModified: $lastModified,
            mimeType: $mimeType,
            extraMetadata: $extraMetadata
        );
    }

    /**
     * @param string $path
     * @param mixed $contents
     * @param Config $config
     * @return void
     */
    private function putObject(string $path, mixed $contents, Config $config): void
    {
        $headers = $config->get('headers', []);
        if ($config->get(Config::OPTION_VISIBILITY) === Visibility::PUBLIC) {
            $headers['x-obs-acl'] = 'public-read';
        }
        if (!array_key_exists('Content-Type', $headers)) {
            $headers['Content-Type'] = $this->mimeTypeDetector->detectMimeType($path, $contents);
        }

        try {
            $this->obsClient->putObject($path, $contents, $headers);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }
}