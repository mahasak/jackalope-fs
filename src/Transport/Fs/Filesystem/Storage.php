<?php

namespace Jackalope\Transport\Fs\Filesystem;

use PHPCR\Util\PathHelper;
use Jackalope\Transport\Fs\NodeSerializer\YamlNodeSerializer;
use PHPCR\Util\UUIDHelper;

class Storage
{
    const INDEX_DIR = '/indexes';
    const WORKSPACE_PATH = '/workspaces';
    const IDX_REFERRERS_DIR = 'referrers';
    const IDX_WEAKREFERRERS_DIR = 'referrers-weak';

    protected $filesystem;
    protected $serializer;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->serializer = new YamlNodeSerializer();
    }

    public function writeNode($workspace, $path, $nodeData)
    {
        // always have a UUID
        if (isset($nodeData['jcr:uuid'])) {
            $uuid = $nodeData['jcr:uuid'];
        } else {
            $uuid = UUIDHelper::generateUUID();
            $nodeData['jcr:uuid'] = $uuid;
            $nodeData[':jcr:uuid'] = 'String';
        }

        $serialized = $this->serializer->serialize($nodeData);
        $absPath = $this->getNodePath($workspace, $path);
        $this->filesystem->write($absPath, $serialized);

        $this->createIndex('uuid', $uuid, $workspace . ':' . $path);

        foreach ($nodeData as $key => $value) {
            if (substr($key, 0, 1) !== ':') {
                continue;
            }

            $propertyName = substr($key, 1);
            $propertyValue = $nodeData[$propertyName];

            if (in_array($value, array('Reference', 'WeakReference'))) {
                $this->appendToIndex(self::IDX_REFERRERS_DIR, $propertyValue, $uuid);
            }

            if (in_array($value, array('WeakReference', 'WeakReference'))) {
                $this->appendToIndex(self::IDX_WEAKREFERRERS_DIR, $propertyValue, $uuid);
            }
        }
    }

    public function readNode($workspace, $path)
    {
        $nodeData = $this->filesystem->read($this->getNodePath($workspace, $path));

        if (!$nodeData) {
            throw new \RuntimeException(sprintf(
                'No node data at path "%s".', $path
            ));
        }

        $node = $this->serializer->deserialize($nodeData);

        return $node;
    }

    public function readNodesByUuids(array $uuids)
    {
        $nodes = array();

        foreach ($uuids as $uuid) {
            $path = self::INDEX_DIR . '/uuid/' . $uuid;

            if (!$this->filesystem->exists($path)) {
                throw new \InvalidArgumentException(sprintf(
                    'Index "%s" of type "%s" does not exist', $uuid, $type
                ));
            }

            $value = $this->filesystem->read($path);
            $workspace = strstr($value, ':', true);
            $path = substr($value, strlen($workspace) + 1);

            $node = $this->readNode($workspace, $path);
            $nodes[$path] = $node;
        }

        return $nodes;
    }

    public function readNodeReferrers($workspace, $path, $weak = false)
    {
        $node = $this->readNode($workspace, $path);
        $uuid = $node->{'jcr:uuid'};


        if ($weak === true) {
            $path = self::INDEX_DIR . '/' . self::IDX_WEAKREFERRERS_DIR . '/' . $uuid;
        } else {
            $path = self::INDEX_DIR . '/' . self::IDX_REFERRERS_DIR . '/' . $uuid;
        }

        if (!$this->filesystem->exists($path)) {
            return array();
        }

        $value = $this->filesystem->read($path);
        $uuids = explode("\n", $value);
        $referrers = $this->readNodesByUuids($uuids);

        return array_keys($referrers);
    }

    public function remove($path, $recursive = false)
    {
        $this->filesystem->remove($path, $recursive);
    }

    public function nodeExists($workspace, $path)
    {
        return $this->filesystem->exists($this->getNodePath($workspace, $path));
    }

    public function workspaceExists($name)
    {
        return $this->filesystem->exists(self::WORKSPACE_PATH . '/' . $name);
    }

    public function workspaceRemove($name)
    {
        $this->filesystem->remove(self::WORKSPACE_PATH . '/' . $name);
    }

    public function workspaceList()
    {
        $list = $this->filesystem->ls(self::WORKSPACE_PATH);
        return $list['dirs'];
    }

    public function workspaceInit($name)
    {
        $this->writeNode($name, '/', array(
            'jcr:primaryType' => 'rep:root',
            ':jcr:primaryType' => 'Name',
        ));
    }

    public function ls($workspace, $path)
    {
        $fsPath = dirname($this->getNodePath($workspace, $path));
        $list = $this->filesystem->ls($fsPath);

        return $list;
    }

    private function createIndex($type, $name, $value)
    {
        $this->filesystem->write(self::INDEX_DIR . '/' . $type . '/' . $name, $value);
    }

    private function appendToIndex($type, $name, $value)
    {
        $indexPath = self::INDEX_DIR . '/' . $type . '/' . $name;

        if (!$this->filesystem->exists($indexPath)) {
            $this->filesystem->write($indexPath, $value);
            return;
        }

        $index = $this->filesystem->read($indexPath);
        $index .= "\n" . $value;
        $this->filesystem->write($indexPath, $index);
    }

    private function getNodePath($workspace, $path)
    {
        $path = PathHelper::normalizePath($path);

        if (substr($path, 0, 1) == '/') {
            $path = substr($path, 1);
        }

        if ($path) {
            $path .= '/';
        }

        $nodeRecordPath = self::WORKSPACE_PATH . '/' . $workspace . '/' . $path . 'node.yml';

        return $nodeRecordPath;
    }
}