<?php
/**
 * Image entity
 */
namespace Minds\Entities;

use Minds\Core;
use Minds\Helpers;

class Image extends File
{
    protected function initializeAttributes()
    {
        parent::initializeAttributes();

        $this->attributes['super_subtype'] = 'archive';
        $this->attributes['subtype'] = "image";
    }

    public function getUrl()
    {
        return elgg_get_site_url() . "media/$this->guid";
    }

    public function getIconUrl($size = 'large')
    {
        global $CONFIG; //@todo remove globals!
        if ($this->time_created <= 1407542400) {
            $size = '';
        }

        if (isset($CONFIG->cdn_url)) {
            $base_url = $CONFIG->cdn_url;
        } else {
            $base_url = \elgg_get_site_url();
        }

        if ($this->access_id != 2) {
            $base_url = \elgg_get_site_url();
        }

        return $base_url. 'api/v1/media/thumbnails/' . $this->guid . '/'.$size;
    }

    /**
     * Extend the default entity save function to update the remote service
     *
     */
    public function save($index = true)
    {
        $this->super_subtype = 'archive';

        parent::save($index);

        //try {
        //    $prepared = new \Minds\Core\Data\Neo4j\Prepared\Common();
        //    \Minds\Core\Data\Client::build('Neo4j')->request($prepared->createObject($this));
        //} catch (\Exception $e) {
        //}

        return $this->guid;
    }

    /**
     * Extend the default delete function to remove from the remote service
     */
    public function delete()
    {
        return parent::delete();

        //remove from the filestore
    }

    /**
     * Return the folder in which this image is stored
     */
    public function getFilePath()
    {
        return str_replace($this->getFilename(), '', $this->getFilenameOnFilestore());
    }


    public function upload($file)
    {
        $this->generateGuid();

        if (!$this->filename) {
            $dir = $this->getFilenameOnFilestore() . "/image/$this->batch_guid/$this->guid";
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        if (!$file['tmp_name']) {
            throw new \Exception("Upload failed. The image may be too large");
        }

        $this->filename = "image/$this->batch_guid/$this->guid/".$file['name'];

        $filename = $this->getFilenameOnFilestore();
        $result = move_uploaded_file($file['tmp_name'], $filename);

        if (!$result) {
            return false;
        }

        return $result;
    }

    public function createThumbnails($sizes = array('small', 'medium','large', 'xlarge'), $filepath = null)
    {
        if (!$sizes) {
            $sizes = array('small', 'medium','large', 'xlarge');
        }
        $master = $filepath ?: $this->getFilenameOnFilestore();
        foreach ($sizes as $size) {
            switch ($size) {
                case 'tiny':
                    $h = 25;
                    $w = 25;
                    $s = true;
                    $u = true;
                    break;
                case 'small':
                    $h = 100;
                    $w = 100;
                    $s = true;
                    $u = true;
                    break;
                case 'medium':
                    $h = 300;
                    $w = 300;
                    $s = true;
                    $u = true;
                    break;
                case 'large':
                    $h = 600;
                    $w = 600;
                    $s = false;
                    $u = true;
                    break;
                case 'xlarge':
                    $h = 1024;
                    $w = 1024;
                    $s = false;
                    $u = true;
                default:
                    continue;
            }
            //@todo - this might not be the smartest way to do this
            $resized = \get_resized_image_from_existing_file($master, $w, $h, $s, 0, 0, 0, 0, $u);
            $this->setFilename("image/$this->batch_guid/$this->guid/$size.jpg");
            $this->open('write');
            $this->write($resized);
            $this->close();
        }
    }

    public function getExportableValues()
    {
        return array_merge(parent::getExportableValues(), array(
        'thumbnail',
                'cinemr_guid',
                'license',
                'mature'
            ));
    }

    public function getAlbumChildrenGuids()
    {
        $db = new Core\Data\Call('entities_by_time');
        $row = $db->getRow("object:container:$this->container_guid", ['limit'=>100]);
        $guids = [];
        foreach ($row as $col => $val) {
            $guids[] = (string) $col;
        }
        return $guids;
    }

    /**
     * Extend exporting
     */
    public function export()
    {
        $export = parent::export();
        $export['thumbnail_src'] = $this->getIconUrl();
        $export['thumbs:up:count'] = Helpers\Counters::get($this->guid, 'thumbs:up');
        $export['thumbs:down:count'] = Helpers\Counters::get($this->guid, 'thumbs:down');
        $export['description'] = $this->description; //videos need to be able to export html.. sanitize soon!
        return $export;
    }

    /**
     * Generates a GUID, if there's none
     */
    public function generateGuid()
    {
        if (!$this->guid) {
            $this->guid = Core\Guid::build();
        }

        return $this->guid;
    }

    /**
     * Patches the entity
     */
    public function patch(array $data = [])
    {
        $this->generateGuid();

        $data = array_merge([
            'title' => null,
            'description' => null,
            'license' => null,
            'mature' => null,
            'hidden' => null,
            'batch_guid' => null,
            'access_id' => null,
            'container_guid' => null
        ], $data);

        $allowed = [
            'title',
            'description',
            'license',
            'hidden',
            'batch_guid',
            'access_id',
            'container_guid'
        ];

        foreach ($allowed as $field) {
            if ($data[$field] === null) {
                continue;
            }

            if ($field == 'access_id') {
                $data[$field] = (int) $data[$field];
            } elseif ($field == 'mature') {
                $this->setFlag('mature', !!$data['mature']);
                continue;
            }

            $this->$field = $data[$field];
        }

        return $this;
    }

    /**
     * Process the entity's assets
     */
    public function setAssets(array $assets)
    {
        $this->generateGuid();

        if (isset($assets['filename'])) {
            $this->filename = $assets['filename'];
        }

        if (isset($assets['media'])) {
            $this->createThumbnails(null, $assets['media']['file']);

            if (strpos($assets['media']['type'], '/gif') !== false) {
                $this->gif = true;
            }
        }

        if (isset($assets['container_guid'])) {
            $this->container_guid = $assets['container_guid'];
        }
    }

    /**
     * Builds the newsfeed Activity parameters
     */
    public function getActivityParameters()
    {
        return [
            'batch',
            [[
                'src' => \elgg_get_site_url() . 'fs/v1/thumbnail/' . $this->guid,
                'href' => \elgg_get_site_url() . 'media/' . ($this->container_guid ? $this->container_guid . '/' : '') . $this->guid,
                'mature' => $this->getFlag('mature')
            ]]
        ];
    }
}
