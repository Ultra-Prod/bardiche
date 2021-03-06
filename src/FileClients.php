<?php

namespace KazuakiM\Bardiche;

/**
 * @copyright KazuakiM <kazuaki_mabuchi_to_go@hotmail.co.jp>
 * @author    KazuakiM <kazuaki_mabuchi_to_go@hotmail.co.jp>
 * @license   http://www.opensource.org/licenses/mit-license.php  MIT License
 *
 * @link      https://github.com/KazuakiM/bardiche
 */
class FileClients //{{{
{
    use Ftp;
    use Ssh;

    // Class variable {{{
    const BARDICHE_UPLOAD = true;
    const BARDICHE_DOWNLOAD = false;
    public $config;
    public $type;
    private static $_defaultCommonConfig = [
        'negotiation' => false,
        'timeout' => 90,
        'host' => '',
        'username' => '',
        'password' => '',
        'file_info' => [
            [
                'remote_directory_path' => '',
                'remote_file_name' => '',
                'local_directory_path' => '',
                'local_file_name' => '',
                'ascii' => FTP_ASCII, // options: ftp and ftps only.
            ],
        ],
    ];
    private static $_defaultConfig = [
        'ftp' => [
            'port' => 21,
            'pasv' => true,
            'parallel' => 0,
        ],
        'ftps' => [
            'port' => 21,
            'pasv' => true,
            'parallel' => 0,
        ],
        'sftp' => [
            'port' => 22,
            'method' => [],
            'callbacks' => [],
            'pubkeyfile' => '',
            'privkeyfile' => '',
        ],
        'scp' => [
            'port' => 22,
            'method' => [],
            'callbacks' => [],
            'pubkeyfile' => '',
            'privkeyfile' => '',
            'permission' => 0644,
        ],
    ];
    //}}}

    public function __construct(FileClientsType $type, array $config) //{{{
    {
        //Init
        $this->type = $type->valueOf();
        $this->_setConfig($config);

        assert(0 < strlen($this->config['host']), BardicheException::getMessageJson("Not found.config['host']"));
        assert(0 < strlen($this->config['port']), BardicheException::getMessageJson("Not found.config['port']"));
        assert(0 < strlen($this->config['timeout']), BardicheException::getMessageJson("Not found.config['timeout']"));

        //Negotiation
        if ($this->config['negotiation']) {
            $this->_negotiation();
        }

        //Connection
        switch ($this->type) {
        case FileClientsType::BARDICHE_TYPE_FTP:
        case FileClientsType::BARDICHE_TYPE_FTPS:
            break;
        case FileClientsType::BARDICHE_TYPE_SFTP:
        case FileClientsType::BARDICHE_TYPE_SCP:
            assert(extension_loaded('ssh2'), BardicheException::getMessageJson('Not found.ssh2'));

            $this->initSsh();
            break;
        }
    } //}}}

    public function __destruct() //{{{
    {
        switch ($this->type) {
        case FileClientsType::BARDICHE_TYPE_FTP:
        case FileClientsType::BARDICHE_TYPE_FTPS:
            $this->closeFtp();
            break;
        case FileClientsType::BARDICHE_TYPE_SFTP:
        case FileClientsType::BARDICHE_TYPE_SCP:
            $this->closeSsh();
            break;
        }
    } //}}}

    private function _setConfig(array $config) //{{{
    {
        $this->config = [];
        foreach (self::$_defaultCommonConfig as $commonKey => $commonValue) {
            $this->config[$commonKey] = $config[$commonKey] ?? $commonValue;
        }
        foreach (self::$_defaultConfig[$this->type] as $key => $value) {
            $this->config[$key] = $config[$key] ?? $value;
        }
    } //}}}

    private function _negotiation() //{{{
    {
        $connection = @fsockopen($this->config['host'], $this->config['port'], $errno, $errstr, $this->config['timeout']);
        if (!$connection) {
            throw new BardicheException(BardicheException::getMessageJson('fsockopen error'));
        }
        @fclose($connection);
    } //}}}

    public function uploadFile() //{{{
    {
        switch ($this->type) {
        case FileClientsType::BARDICHE_TYPE_FTP:
        case FileClientsType::BARDICHE_TYPE_FTPS:
            $this->uploadFtp();
            break;
        case FileClientsType::BARDICHE_TYPE_SFTP:
            $this->uploadSftp();
            break;
        case FileClientsType::BARDICHE_TYPE_SCP:
            $this->uploadScp();
            break;
        default:
            throw new BardicheException(BardicheException::getMessageJson(sprintf('type:%s error', $this->type)));
            break;
        }
    } //}}}

    public function downloadFile() //{{{
    {
        switch ($this->type) {
        case FileClientsType::BARDICHE_TYPE_FTP:
        case FileClientsType::BARDICHE_TYPE_FTPS:
            $this->downloadFtp();
            break;
        case FileClientsType::BARDICHE_TYPE_SFTP:
            $this->downloadSftp();
            break;
        case FileClientsType::BARDICHE_TYPE_SCP:
            $this->downloadScp();
            break;
        default:
            throw new BardicheException(BardicheException::getMessageJson(sprintf('type:%s error', $this->type)));
            break;
        }
    } //}}}

    public static function one(FileClientsType $type, array $config, bool $upload) //{{{
    {
        $model = new self($type, $config);
        if ($upload) {
            $model->uploadFile();
        } else {
            $model->downloadFile();
        }
        $model->__destruct();
    } //}}}

    public function setOptions(array $options) //{{{
    {
        foreach ($options as $key => $value) {
            $this->setValue($key, $value);
        }
    } //}}}

    public function setValue(string $key, $value) //{{{
    {
        assert(array_key_exists($key, self::$_defaultCommonConfig) ? true : array_key_exists($key, self::$_defaultConfig[$this->type]), BardicheException::getMessageJson(sprintf("Not found.config['%s']", $key)));

        $this->config[$key] = $value;
    } //}}}

    public function getConfig() //{{{
    {
        return $this->config;
    } //}}}

    public static function getRemoteFilePath(array $fileInfoArray) //{{{
    {
        assert(FileClientsTest::assertFilePath($fileInfoArray, 'remote_directory_path', 'remote_file_name'));

        return self::_getFilePath($fileInfoArray['remote_directory_path'], $fileInfoArray['remote_file_name']);
    } //}}}

    public static function getUploadLocalFilePath(array $fileInfoArray) //{{{
    {
        assert(FileClientsTest::assertFilePath($fileInfoArray, 'local_directory_path', 'local_file_name'));

        $uploadLocalFilePath = self::_getFilePath($fileInfoArray['local_directory_path'], $fileInfoArray['local_file_name']);
        if (!@file_exists($uploadLocalFilePath)) {
            throw new BardicheException(BardicheException::getMessageJson('file_exists error.'));
        }

        return $uploadLocalFilePath;
    } //}}}

    public static function getDownloadLocalFilePath(array $fileInfoArray) //{{{
    {
        assert(FileClientsTest::assertFilePath($fileInfoArray, 'local_directory_path', 'local_file_name'));

        return self::_getFilePath($fileInfoArray['local_directory_path'], $fileInfoArray['local_file_name']);
    } //}}}

    private static function _getFilePath(string $directoryPath, string $fileName) //{{{
    {
        return sprintf('%s/%s', rtrim($directoryPath, '/'), $fileName);
    } //}}}
} //}}}
