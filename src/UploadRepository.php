<?php
/*
ALTER TABLE `caiss_custom`.`cos_bucket`
ADD COLUMN `app_id` varchar(191) NULL DEFAULT '' COMMENT 'appId' AFTER `desc`,
ADD COLUMN `secret_id` varchar(191) NULL DEFAULT '' COMMENT 'secretId' AFTER `app_id`,
ADD COLUMN `secret_key` varchar(191) NULL DEFAULT '' COMMENT 'secretKey' AFTER `secret_id`,
ADD COLUMN `cdn` varchar(191) NULL DEFAULT '' COMMENT 'CDN加速链接 https://cdn.xxx.com/' AFTER `secret_key`;
*/

namespace GdShenrun\Caiss\File;


use App\Exceptions\CssException;
use App\Models\COS\CosBucket;
use App\Models\COS\CosObject;
use Illuminate\Support\Facades\Cache;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use Qcloud\Cos\Client;

class UploadRepository
{
    private $config = [];

    // model
    protected $bucketModel;
    protected $objectModel;

    // cache key
    const GET_BUCKET_BY_ID   = "get_bucket_by_id:%d";
    const GET_BUCKET_BY_NAME = "get_bucket_by_name:%s";

    public function __construct(CosBucket $bucketModel, CosObject $objectModel)
    {
        $this->bucketModel = $bucketModel;
        $this->objectModel = $objectModel;
    }

    /**
     * 获取COS操作的基础对象（cosClient）
     * @param string $bucketName
     * @return Client
     * @throws UploadException
     * @throws \Throwable
     */
    public function getCosClient(string $bucketName) : Client
    {
        $client = Di::getInstance()->get($bucketName);
        if ($client) {
            return $client;
        }
        $bucketConfig = $this->getConfigByBucketName($bucketName, true);
        $client = new Client([
            'bucket' => $bucketConfig['bucket'],
            'region' => $bucketConfig['region'],
            'endpoint' => $bucketConfig['endpoint'],
            'schema' => 'https',
            'credentials' => [
                'appId' => $bucketConfig['appId'],
                'secretId' => $bucketConfig['secretId'],
                'secretKey' => $bucketConfig['secretKey'],
            ],
            'connect_timeout' => 10, // TCP握手
            'timeout' => 180, // TCP传输
        ]);
        Di::getInstance()->set($bucketName, $client);
        return $client;
    }

    /**
     * 根据bucket数据库ID获取配置
     * @param int $bucketId
     * @param bool $isThrow 是否抛异常
     * @return mixed
     * @throws UploadException
     */
    public function getConfigByBucketId(int $bucketId, $isThrow = false)
    {
        //更新修改 3分钟生效
        $cacheKey = sprintf(self::GET_BUCKET_BY_ID, $bucketId);
        $bucket =  Cache::remember($cacheKey, 180, function () use ($bucketId) {
            return $this->bucketModel->where('id', $bucketId)->first();
        });
        if($isThrow && !$bucket){
            throw new UploadException("无此存储桶", 102203);
        }
        return $bucket;
    }

    /**
     * 根据bucket名称获取配置
     * @param string $bucketName
     * @param bool $isThrow 是否抛异常
     * @return mixed
     * @throws UploadException
     */
    public function getConfigByBucketName(string $bucketName, $isThrow = false)
    {
        $cacheKey = sprintf(self::GET_BUCKET_BY_NAME, $bucketName);
        $bucketConfig = Cache::remember($cacheKey, 180, function () use ($bucketName) {
            return $this->bucketModel->where('bucket', $bucketName)->first();
        });
        if($isThrow && !$bucket){
            throw new UploadException("无此存储桶", 102203);
        }
        return $bucket;
    }

    /**
     * 上传磁盘文件
     * @param String $bucketName bucket名称
     * @param String $directory 目录名
     * @param String $directory 扩展名
     * @param String $localFilePath 文件的本地磁盘绝对路径
     * @return string objectKey 对象键
     * @throws UploadException
     * @comment 自动计算文件名
     */
    public function uploadFile(string $bucketName, string $directory, string $extension, string $localFilePath)
    {
        try{
            // (图片)文件抓取
            if (strpos($localFilePath, "http") === 0) {
                $tempFilePath = tempnam(sys_get_temp_dir(), 'down'); // 生成临时文件
                if ($tempFilePath === false) {
                    throw new UploadException('Create temp file failure or disk is full');
                }
                $content = file_get_contents($localFilePath); // 下载
                if (false === $content) {
                    throw new UploadException('下载失败');
                }
                if (false === file_put_contents($tempFilePath, $content) ){ // 写入临时文件
                    throw new UploadException('Temp file written failure or disk is full');
                }
                $localFilePath = $tempFilePath; // 抓取完毕
            }

            // bucket
            $bucketName = $bucketName ?: 'default';
            // Key
            $etagInfo = \GdShenrun\Caiss\File\Etag::sum($localFilePath); // 文件hash值,避免重复上传文件
            if ($etagInfo[1] !== null) {
                throw new UploadException('etag error '. mb_convert_encoding( $etagInfo[1], 'UTF-8', 'UTF-8,GBK,GB2312,BIG5'));
            }
            $objectKey = trim($directory, '/') . '/' . $etagInfo[0] . '.' . $extension; // 文件夹 + 文件名 + 扩展名
            // file resource
            $fileResource = fopen($localFilePath, 'rb');
            if (false === $fileResource) {
                throw new UploadException("无法打开文件");
            }
            // curl upload
            $cosClient  = $this->getCosClient($bucketName);
            $res =  $cosClient->upload($bucketName, $objectKey, $fileResource);
            $res = $res->toArray();
            return $res['Key'] ?? ''; // 小于5GB,有Key
        }catch (\Exception $e){
            throw new UploadException("上传文件失败，原因：".$e->getMessage());
        } finally {
            isset($tempFilePath) && $tempFilePath && unlink($tempFilePath);
            isset($fileResource) && $fileResource && fclose($fileResource);
        }
    }

    /**
     * 上传base64图片
     * @param string $bucketName bucket名称
     * @param string $directory 保存至文件夹
     * @param string $base64Img base64图片(不一定要MIME类型声明)
     * @return string objectKey
     * @throws UploadException
     * @comment 自动计算文件名
     */
    public function uploadBase64Img(string $bucketName, string $directory, string $base64Img) {
        // 'data:image/' . $img_type . ';base64,' . $file_content
        $extension = 'jpg'; // 默认格式jpeg
        if (strpos($base64Img, 'data:image') === 0) {
            $base64Img = explode(',', $base64Img); //截取data:image/png;base64, 这个逗号后的字符
            if (strlen($base64Img[0]) < 20 || !isset($base64Img[1])) { // 扩展名至少2个字符串, 有图片数据, 禁止空白图片
                throw new UploadException('Illegal base64 string');
            }
            $base64Img = $base64Img[1];
            $extension = substr($base64Img[1], 11,-7);
        }
        $imgBinary = base64_decode($base64Img);//对截取后的字符使用base64_decode进行解码
        if ($imgBinary === false) {
            throw new UploadException('Base64 decode error');
        }
        $tempFilePath = tempnam(sys_get_temp_dir(), 'img'); // 生成临时文件
        if ($tempFilePath === false) {
            throw new UploadException('Create temp file failure or disk is full');
        }
        if (false === file_put_contents($tempFilePath, $imgBinary) ){ // 写入临时文件
            throw new UploadException('Temp file written failure or disk is full');
        }

        try{
            $objectKey = $this->uploadFile($bucketName, $directory, $extension, $tempFilePath); // 上传
            return $objectKey;
        }catch (\Exception $e) {
            throw new UploadException("上传图片失败，原因：".$e->getMessage());
        } finally {
            file_exists($tempFilePath) && unlink($tempFilePath); // 上传完毕, 删除临时文件
        }
    }

    /**
     * 获取永久的下载链接
     * @param string $bucketName 桶名称
     * @param string $objectKey 对象名(含目录名)
     * @return string 完整的下载链接
     * @throws UploadException
     */
    public function getUrl(string $bucketName, string $objectKey) {
        $config = $this->getConfigByBucketName($bucketName);
        return ($config['cdn'] ?: $config['endpoint']) . $objectKey;
    }

    /**
     * 获取临时的下载链接
     * @param string $bucketName 桶名称
     * @param string $objectKey 对象名(含目录名)
     * @return string 完整的下载链接
     * @throws UploadException
     */
    public function getTempUrl(string $bucketName, string $objectKey) {
        $config = $this->getConfigByBucketName($bucketName);
        return ($config['cdn'] ?: $config['endpoint']) . $objectKey . '';
    }

//    // 文件夹列表
//    public function getDirList($companyId, $pid, $all = false){
//
//    }
//
//    // 添加文件夹
//    public function addDir($companyId, $pid, $dirName, $dirDesc, $private = false){
//        $acl = $private ? 'private' : 'public-read';
//    }
//
//    // 更新文件夹
//    public function updateDir($companyId, $dirId, $dirName, $dirDesc, $private = false){
//
//    }
//
//    // 删除文件夹
//    public function deleteDir($companyId, $dirId) {
//
//    }
}
