<?php

namespace Spatie\MediaLibrary\FileAdder;

use Illuminate\Contracts\Cache\Repository;
use Spatie\MediaLibrary\Exceptions\FileCannotBeImported;
use Spatie\MediaLibrary\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\Exceptions\FilesystemDoesNotExist;
use Spatie\MediaLibrary\Exceptions\FileTooBig;
use Spatie\MediaLibrary\Filesystem;
use Spatie\MediaLibrary\Media;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileAdder
{
    /**
     * @var \Illuminate\Database\Eloquent\Model subject
     */
    protected $subject;

    /**
     * @var Filesystem
     */
    protected $fileSystem;
    /**
     * @var Repository
     */
    protected $config;

    /**
     * @var bool
     */
    protected $preserveOriginal = false;

    /**
     * @var string|\Symfony\Component\HttpFoundation\File\UploadedFile
     */
    protected $file;

    /**
     * @var array
     */
    protected $customProperties = [];

    /**
     * @var string
     */
    protected $pathToFile;
    /**
     * @var string
     */
    protected $fileName;

    /**
     * @var string
     */
    protected $mediaName;

    /**
     * @var string
     */
    protected $diskName = '';

    /**
     * @param Filesystem $fileSystem
     * @param Repository $config
     */
    public function __construct(Filesystem $fileSystem, Repository $config)
    {
        $this->fileSystem = $fileSystem;
        $this->config = $config;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $subject
     *
     * @return FileAdder
     */
    public function setSubject($subject)
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Set the file that needs to be imported.
     *
     * @param string|\Symfony\Component\HttpFoundation\File\UploadedFile $file
     *
     * @return $this
     *
     * @throws FileCannotBeImported
     */
    public function setFile($file)
    {
        $this->file = $file;

        if (is_string($file)) {
            $this->pathToFile = $file;
            $this->fileName = pathinfo($file, PATHINFO_BASENAME);
            $this->mediaName = pathinfo($file, PATHINFO_FILENAME);

            return $this;
        }

        if ($file instanceof UploadedFile) {
            $this->pathToFile = $file->getPath().'/'.$file->getFilename();
            $this->fileName = $file->getClientOriginalName();
            $this->mediaName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

            return $this;
        }

        throw new FileCannotBeImported('Only strings and UploadedFileObjects can be imported');
    }

    /**
     * When adding the file the medialibrary, the original file
     * will be preserved.
     *
     * @return $this
     */
    public function preservingOriginal()
    {
        $this->preserveOriginal = true;

        return $this;
    }

    /**
     * Set the metadata.
     *
     * @param array $customProperties
     *
     * @return $this
     */
    public function withCustomProperties(array $customProperties)
    {
        $this->customProperties = $customProperties;

        return $this;
    }

    /**
     * Set the target media collection to default.
     * Will also start the import process.
     *
     * @param string $collectionName
     * @param string $diskName
     *
     * @return Media
     *
     * @throws FileDoesNotExist
     * @throws FileTooBig
     */
    public function toMediaLibrary($collectionName = 'default', $diskName = '')
    {
        return $this->toCollectionOnDisk($collectionName, $diskName);
    }

    /**
     * Set the target media collection to default.
     * Will also start the import process.
     *
     * @param string $collectionName
     * @param string $diskName
     *
     * @return Media
     *
     * @throws FileDoesNotExist
     * @throws FileTooBig
     */
    public function toMediaLibraryOnDisk($collectionName = 'default', $diskName = '')
    {
        return $this->toCollectionOnDisk($collectionName, $diskName);
    }

    /**
     * Set the collection name where to import the file.
     * Will also start the import process.
     *
     * @param string $collectionName
     * @param string $diskName
     *
     * @return Media
     *
     * @throws FileDoesNotExist
     * @throws FileTooBig
     */
    public function toCollection($collectionName = 'default', $diskName = '')
    {
        return $this->toCollectionOnDisk($collectionName, $diskName);
    }

    public function toCollectionOnDisk($collectionName = 'default', $diskName = '')
    {
        if (!is_file($this->pathToFile)) {
            throw new FileDoesNotExist();
        }

        if (filesize($this->pathToFile) > config('laravel-medialibrary.max_file_size')) {
            throw new FileTooBig();
        }

        $media = new Media();

        $media->name = $this->mediaName;
        $media->file_name = $this->fileName;
        $media->disk = $this->determineDiskName($diskName);

        $media->collection_name = $collectionName;

        $media->size = filesize($this->pathToFile);
        $media->custom_properties = $this->customProperties;
        $media->manipulations = [];

        $media->save();

        $this->subject->media()->save($media);

        $this->fileSystem->add($this->pathToFile, $media, $this->fileName);

        if (! $this->preserveOriginal) {
            unlink($this->pathToFile);
        }

        return $media;
    }

    /**
     * Determine the disk to be used.
     *
     * @param string $diskName
     * @return string
     * @throws FilesystemDoesNotExist
     */
    protected function determineDiskName($diskName)
    {
        if ($diskName == '') {
            $diskName = config('laravel-medialibrary.defaultFilesystem');
        }

        if (is_null(config("filesystems.disks.{$diskName}"))) {
            throw new FilesystemDoesNotExist("There is no filesystem named {$diskName}");
        }

        return $diskName;
    }
}
