<?php

namespace League\Flysystem\Azure;

use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Config;
use League\Flysystem\Util;
use WindowsAzure\Blob\Internal\IBlob;
use WindowsAzure\Blob\Models\Blob;
use WindowsAzure\Blob\Models\BlobProperties;
use WindowsAzure\Blob\Models\CopyBlobResult;
use WindowsAzure\Blob\Models\GetBlobPropertiesResult;
use WindowsAzure\Blob\Models\GetBlobResult;
use WindowsAzure\Blob\Models\ListBlobsOptions;
use WindowsAzure\Blob\Models\ListBlobsResult;
use WindowsAzure\Common\ServiceException;

class AzureAdapter extends AbstractAdapter implements AdapterInterface
{
    use NotSupportingVisibilityTrait;

    /**
     * @var string
     */
    protected $container;

    /**
     * @var \WindowsAzure\Blob\Internal\IBlob
     */
    protected $client;

    /**
     * Constructor.
     *
     * @param IBlob  $azureClient
     * @param string $container
     * @param string $prefix
     */
    public function __construct(IBlob $azureClient, $container, $prefix = null)
    {
        $this->client = $azureClient;
        $this->container = $container;
        $this->setPathPrefix($prefix);
    }

    /**
     * Gets the Azure Client
     * @return IBlob
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Gets the container name
     * @return string
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        // Prefix the path
        $path = $this->applyPathPrefix($path);

        // Prefix the new path
        // TODO: should we attempt to prefix the $newpath here?
        $newpath = $this->applyPathPrefix($newpath);

        $this->client->copyBlob($this->container, $newpath, $this->container, $path);

        return $this->delete($path);
    }

    public function copy($path, $newpath)
    {
        // Prefix the path
        $path = $this->applyPathPrefix($path);

        // Prefix the new path
        // TODO: should we attempt to prefix the $newpath here?
        $newpath = $this->applyPathPrefix($newpath);

        /** @var CopyBlobResult $result */
        $this->client->copyBlob($this->container, $newpath, $this->container, $path);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        // Prefix the path
        $path = $this->applyPathPrefix($path);

        $this->client->deleteBlob($this->container, $path);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        // Prefix the path
        $dirname = $this->applyPathPrefix($dirname);

        $options = new ListBlobsOptions();
        $options->setPrefix($dirname);

        /** @var ListBlobsResult $listResults */
        $listResults = $this->client->listBlobs($this->container, $options);

        foreach ($listResults->getBlobs() as $blob) {
            /** @var Blob $blob */
            $this->client->deleteBlob($this->container, $blob->getName());
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        // Prefix the path
        $dirname = $this->applyPathPrefix($dirname);

        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        // Prefix the path
        $path = $this->applyPathPrefix($path);

        try {
            $this->client->getBlobMetadata($this->container, $path);
        } catch (ServiceException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        // Prefix the path
        $path = $this->applyPathPrefix($path);

        /** @var GetBlobResult $blobResult */
        $blobResult = $this->client->getBlob($this->container, $path);
        $properties = $blobResult->getProperties();
        $content = $this->streamContentsToString($blobResult->getContentStream());

        return $this->normalizeBlobProperties($path, $properties) + ['contents' => $content];
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        // Prefix the path
        $path = $this->applyPathPrefix($path);

        /** @var GetBlobResult $blobResult */
        $blobResult = $this->client->getBlob($this->container, $path);
        $properties = $blobResult->getProperties();

        return $this->normalizeBlobProperties($path, $properties) + ['stream' => $blobResult->getContentStream()];
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        // Prefix the path
        $directory = $this->applyPathPrefix($directory);

        $options = new ListBlobsOptions();
        $options->setPrefix($directory);

        /** @var ListBlobsResult $listResults */
        $listResults = $this->client->listBlobs($this->container, $options);

        $contents = [];
        foreach ($listResults->getBlobs() as $blob) {
            $contents[] = $this->normalizeBlobProperties($blob->getName(), $blob->getProperties());
        }

        return $contents;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        // Prefix the path
        $path = $this->applyPathPrefix($path);

        /** @var GetBlobPropertiesResult $result */
        $result = $this->client->getBlobProperties($this->container, $path);

        return $this->normalizeBlobProperties($path, $result->getProperties());
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Builds the normalized output array.
     *
     * @param string $path
     * @param int    $timestamp
     * @param mixed  $content
     *
     * @return array
     */
    protected function normalize($path, $timestamp, $content = null)
    {
        $data = [
            'path'      => $path,
            'timestamp' => (int) $timestamp,
            'dirname'   => Util::dirname($path),
            'type'      => 'file',
        ];

        if (is_string($content)) {
            $data['contents'] = $content;
        }

        return $data;
    }

    /**
     * Builds the normalized output array from a Blob object.
     *
     * @param string         $path
     * @param BlobProperties $properties
     *
     * @return array
     */
    protected function normalizeBlobProperties($path, BlobProperties $properties)
    {
        return [
            'path'      => $path,
            'timestamp' => (int) $properties->getLastModified()->format('U'),
            'dirname'   => Util::dirname($path),
            'mimetype'  => $properties->getContentType(),
            'size'      => $properties->getContentLength(),
            'type'      => 'file',
        ];
    }

    /**
     * Retrieves content streamed by Azure into a string.
     *
     * @param resource $resource
     *
     * @return string
     */
    protected function streamContentsToString($resource)
    {
        return stream_get_contents($resource);
    }

    /**
     * Upload a file.
     *
     * @param string $path
     * @param mixed  $contents Either a string or a stream.
     * @param Config $config
     *
     * @return array
     */
    protected function upload($path, $contents, Config $config)
    {
        // Prefix the path
        $path = $this->applyPathPrefix($path);

        /** @var CopyBlobResult $result */
        $result = $this->client->createBlockBlob($this->container, $path, $contents);

        return $this->normalize($path, $result->getLastModified()->format('U'), $contents);
    }
}
