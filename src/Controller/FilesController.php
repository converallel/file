<?php

namespace App\Controller;

use Cake\Filesystem\File;
use Cake\Filesystem\Folder;
use Cake\Http\Client;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\Exception\NotFoundException;

/**
 * Files Controller
 *
 */
class FilesController extends AppController
{

    public function view($url = null)
    {
        $url = FILES . urldecode($url);
        if (!file_exists($url)) {
            throw new NotFoundException();
        }

        $response = $this->getResponse()->withEtag(md5_file($url));
        if ($response->checkNotModified($this->getRequest())) {
            return $response->withStatus(304);
        }

        $download = (bool)($this->getRequest()->getQueryParams()['download'] ?? false);
        return $response->withFile($url, ['download' => $download])->withModified(filemtime($url));
    }

    public function add()
    {
        //TODO: Add auth
        $request = $this->getRequest();

        $saved_files = [];
        $blobName = 'blob';
        $postData = $request->getData();
        foreach ($postData as $file_key => $file_data) {
            // find the uploaded file
            $uploadedFile = $this->getRequest()->getUploadedFile("$file_key.$blobName");
            if (!$uploadedFile) {
                throw new BadRequestException();
            }

            // folder initialization
            $filename = uniqid(time());
            $extension = strtolower(pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION));
            $folder = new Folder(FILES . $extension . DS, true, 0755);

            // if file is an image, create image folder and add dimension to the file name
            $tmp_name = $file_data[$blobName]['tmp_name'];
            $mime_type = getFileMimeType($tmp_name);
            if ($mime_type === 'image' && $info = getimagesize($tmp_name)) {
                $folder = new Folder($folder->path . $filename . DS, true, 0755);
                $width = $info[0];
                $height = $info[1];
                $filename = "{$width}x$height";
            }

            // create the file
            $file = new File($folder->path . $filename . '.' . $extension, true, 0644);

            // clean up and throw exception if failed to save file
            if (!$file->write($uploadedFile->getStream())) {
                foreach ($saved_files as $saved_file) {
                    $mime_type = getFileMimeType($saved_file->path);
                    $mime_type === 'image' ? $saved_file->Folder->delete() : $saved_file->delete();
                }
                throw new InternalErrorException('Upload failed, please try again.');
            }
            $saved_files[] = $file;

            // prepare data to be sent to the api server
            unset($postData[$file_key], $file_data[$blobName]);
            $postData[] = array_merge($file_data, [
                'user_id' => $this->current_user->id,
                'url' => 'http://' . $request->host() . '/' . str_ireplace(FILES, '',
                        $file->Folder->path) . '/' . $file->name() . '.' . $file->ext(),
                'mime_type' => $file->mime(),
                'size' => $file->size()
            ]);
        }

        // send request to app server to record data entry
        $url = APP_SERVER . 'files';
        $postHeaders = array_intersect_key($request->getHeaders(),
            array_flip(['X-Csrf-Token', 'Cookie', 'Connection']));
        $postHeaders['Accept'] = 'application/json';
        $postResponse = (new Client())->post($url, $postData, ['headers' => $postHeaders]);

        // if post request failed, delete all files and set response status code accordingly
        if (!$postResponse->isOk()) {
            foreach ($saved_files as $saved_file) {
                $mime_type = getFileMimeType($saved_file->path);
                $mime_type === 'image' ? $saved_file->Folder->delete() : $saved_file->delete();
            }
            $this->setResponse($this->getResponse()->withStatus($postResponse->getStatusCode()));
        }

        // set response
        $data = json_decode($postResponse->getBody()->getContents(), true);
        if (count($data) === 1 && array_key_exists(0, $data)) {
            $data = $data[0];
        }
        $this->set(array_merge($data, ['_serialize' => array_keys($data)]));
    }

    public function delete($url = null)
    {
        //TODO: add auth
        $urls = $this->getRequest()->getData() ?? [$url];
        foreach ($urls as $url) {
            $url = FILES . $url;
            $file = new File($url);

            if (!$file->exists()) {
                throw new NotFoundException();
            }
            if (getFileMimeType($url) === 'image') {
                $file->Folder->delete();
            } elseif (!$file->delete()) {
                throw new InternalErrorException('Failed to delete the file, please try again.');
            }
        }

        return $this->getResponse();
    }
}
