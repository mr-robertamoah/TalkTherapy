<?php

namespace App\Services;

use App\DTOs\FileUploadDTO;
use App\Models\Counsellor;
use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FileService extends Service
{
    public function uploadFile(FileUploadDTO $fileUploadDTO)
    {
        if (is_null($fileUploadDTO->file)) return null;

        $disk = env('FILESYSTEM_DISK', $fileUploadDTO->disk);
        
        $data = [];
        $name = $this->getNameOfFile($fileUploadDTO->file);
        $data['mime'] = $fileUploadDTO->file->getClientMimeType();
        $data['size'] = $fileUploadDTO->file->getSize();
        $data['storage'] = $disk;
        $data['name'] = $name;
        $data['path'] = $fileUploadDTO->path;

        Storage::disk($disk)->put($fileUploadDTO->path . '/' . $name, $fileUploadDTO->file);

        return $data;
    }

    private function getNameOfFile(UploadedFile $file)
    {
        return sha1($file->getClientOriginalName());
    }

    public function saveFile(User|Counsellor $addedby, array $data): File
    {
        $file = $addedby->addedFiles()->create($data);

        return $file;
    }

    public function deleteFile(File|null $file)
    {
        if (is_null($file)) return;

        Storage::disk($file->storage)->delete($file->path);

        return $file->delete();
    }
}