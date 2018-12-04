<?php

namespace App\Controller;

use Cake\Filesystem\File;
use Cake\Filesystem\Folder;
use Cake\Http\Client;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;

/**
 * Files Controller
 *
 */
class FilesController extends AppController
{

    public function view($url = null)
    {
        $url = FILES . $url;
        if (!file_exists($url))
            throw new NotFoundException();

        $response = $this->getResponse()->withEtag(md5_file($url));
        if ($response->checkNotModified($this->getRequest()))
            return $response->withStatus(304);

        $download = (bool)($this->getRequest()->getQueryParams()['download'] ?? false);
        return $response->withFile($url, ['download' => $download])->withModified(filemtime($url));
    }

    public function add()
    {
        //TODO: Add auth
        $request = $this->getRequest();

        $file_key = 'file';
        $uploadedFile = $request->getUploadedFile($file_key);
        if (!$uploadedFile)
            throw new \Cake\Http\Exception\BadRequestException();
        $filename = uniqid(time());
        $extension = strtolower(pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION));
        $folder = new Folder(FILES . $extension . DS, true, 0755);

        // if file is an image, create image folder and add dimension to the file name
        $mime_type = getFileMimeType($_FILES[$file_key]['tmp_name']);
        if ($mime_type === 'image' && $info = getimagesize($_FILES[$file_key]['tmp_name'])) {
            $folder = new Folder($folder->path . $filename . DS, true, 0755);
            $width = $info[0];
            $height = $info[1];
            $filename = "{$width}x$height";
        }
        $file = new File($folder->path . $filename . '.' . $extension, true, 0644);

        // clean up and throw exception if failed to save file
        if (!$file->write($uploadedFile->getStream())) {
            $mime_type === 'image' ? $folder->delete() : $file->delete();
            throw new InternalErrorException('Failed to save the file, please try again.');
        }

        // send request to app server to record data entry
        $url = APP_SERVER . (in_array($mime_type, ['image', 'audio', 'video']) ? 'media' : 'files');
        $headers = array_intersect_key($request->getHeaders(), array_flip(['Accept', 'X-Csrf-Token', 'Cookie', 'Connection']));
        $data = array_merge($request->getData(), [
            'user_id' => $this->current_user->id,
            'server' => $_SERVER['HTTP_HOST'],
            'directory' => str_ireplace(FILES, '', $file->Folder->path),
            'name' => $file->name(),
            'extension' => $file->ext(),
            'size' => $file->size()
        ]);
        unset($data[$file_key]);
        $postResponse = (new Client())->post($url, $data, ['headers' => $headers]);

        // return response to user
        $response = new Response(['body' => $postResponse->getBody()]);
        $headers = $postResponse->getHeaders();
        unset($headers['Host'], $headers['Date'], $headers['Connection'], $headers['X-Powered-By']);
        foreach ($headers as $key => $value)
            $response = $response->withHeader($key, $value);

        return $response;
    }

    public function delete($url = null)
    {
        //TODO: add auth
        $url = FILES . $url;
        $file = new File($url);

        if (!$file->exists())
            throw new NotFoundException();
        if (getFileMimeType($url) === 'image')
            $file->Folder->delete();
        elseif (!$file->delete())
            throw new InternalErrorException('Failed to delete the file, please try again.');

        return $this->getResponse();
    }
}
