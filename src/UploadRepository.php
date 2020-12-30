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
use Qcloud\Cos\Exception\ServiceResponseException;

class UploadRepository extends AbstractUploadRepository
{
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
        $bucketConfig =  Cache::remember($cacheKey, 3, function () use ($bucketId) {
            return $this->bucketModel->where('id', $bucketId)->first();
        });
        if($isThrow && !$bucketConfig){
            throw new UploadException("无此存储桶", 102203);
        }
        return $bucketConfig;
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
        $bucketConfig = Cache::remember($cacheKey, 3, function () use ($bucketName) {
            return $this->bucketModel->where('bucket', $bucketName)->first();
        });
        if($isThrow && !$bucketName){
            throw new UploadException("无此存储桶", 102203);
        }
        return $bucketConfig;
    }


    /**
     * 递归遍历文件夹(树形结构)
     * @param string $bucketName 桶名称
     * @param string $prefix 目录前缀,子文件夹必须以
     * @return array
     */
    public function cmdList(string $bucketName, string $prefix = '') :array {
        $prefix = trim($prefix, '/');
        if ($prefix !== '') {
            $prefix .= '/';
        }
        $data = $this->cmdDir($bucketName, $prefix);
        $tree = array_merge($data['dirList'], $data['fileList']);
        if (count($data['dirList']) === 0) {
            return $tree;
        }

        foreach($tree as $index => $node) {
            if ($node['Type'] !== 'dir') {
                continue;
            }
            $children = $this->cmdList($bucketName, $node['Key']);
            $tree[$index]['children'] = $children;
        }
        return $tree;
    }

    /**
     * 列出文件夹的 文件列表和子文件夹列表(dir,ll,ls)
     * @param string $bucketName 桶名称
     * @param string $prefix
     * @param string $marker
     * @return array
     * @throws UploadException
     * @throws \Throwable
     */
    public function cmdDir(string $bucketName, string $prefix = '', string $marker = '') :array {
        $prefix = trim($prefix, '/');
        if ($prefix !== '') {
            $prefix .= '/';
        }
        /**
         * @var Client $client
         * @var \GuzzleHttp\Command\Result $result
         */
        $client = $this->getCosClient(config('myqcloud.publicBucket'));
        $result = $client->ListObjects(array(
            'Bucket' =>  $bucketName,
            'Prefix' => $prefix,
            'Delimiter' => '/', // Contents NextMarker
            'MaxKeys' => 2,
            'Marker' => $marker,
        ));
        $data = $result->toArray();

        $fileList = []; // Key
        $dirList = []; // Prefix
        // 文件
        if (isset($data['Contents']) && count($data['Contents']) > 0) {
            foreach ($data['Contents'] as $k => $item) {
                if (bccomp($item['Size'], 0, 0) === 0) { // 文件大小为0, 人为判定 是 当前文件夹 ./
                    continue;
                }
                array_push($fileList, [
                    'Type'         => 'file',
                    'Prefix'       => $prefix, // 文件夹
                    'Name'         => substr($item['Key'], strlen($prefix)),
                    'Key'          => $item['Key'], // 文件名 oss fullname, cos fullname
                    'Size'         => $item['Size'],
                    'LastModified' => date('Y-m-d H:i:s', strtotime($item['LastModified'])), // oss ISO 8601, cos RFC1123
                    'ETag'         => trim($item['ETag'], '"'),
                ]);
            }
        }
        // 子文件夹
        if (isset($data['CommonPrefixes']) && count($data['CommonPrefixes']) > 0) {
            foreach($data['CommonPrefixes'] as $item){
                array_push($dirList, [
                    'Type'   => 'dir',
                    'Prefix' => $prefix,
                    'Name'   => substr($item['Prefix'], strlen($prefix)),
                    'Key'    => $item['Prefix']
                ]);
            }
        }
        // 更多数据
        if ($data['IsTruncated'] === true){ // IsTruncated false,true --> NextMarker
            $nextPage = $this->cmdDir($prefix, $data['NextMarker']);
            if ($nextPage) {
                $fileList = array_merge($fileList, $nextPage['fileList']);
                $dirList = array_merge($dirList, $nextPage['dirList']);
            }
        }
        return compact('fileList', 'dirList');
    }

    // 删除多个文件
    public function cmdDeleteObjects(string $bucketName, array $objectKeyList) :bool {
        if (count($objectKeyList) === 0) {
            return true;
        }
        /**
         * @var Client $client
         * @var \GuzzleHttp\Command\Result $result
         */
        $client = $this->getCosClient(config('myqcloud.publicBucket'));

        $chunks = array_chunk($objectKeyList, 1000, false); // 每次操作最大1000个对象
        foreach($chunks as $index => $chunk) {

            $result = $client->deleteObjects([
                'Bucket' => $bucketName,
                'Quiet' => false,
                'Objects' => array_map(function ($objectKey) {
                    return ['Key' => $objectKey];
                }, $chunk),
            ]);
            $result = $result->toArray();
            if ( count($result['Deleted']) !== count($objectKeyList) ){
                return false;
            }
        }
        return true;
    }

    // 删除文件夹
    public function cmdDeleteDir(string $bucketName, string $prefix) {
        // 列出文件夹
        [$fileList, $dirList] = $this->cmdDir($prefix);
        // 删除当前文件夹 的 文件file
        $success = $this->cmdDeleteObjects($bucketName, array_map(function ($objectInfo) {
            return $objectInfo['Key'];
        }, $fileList));
        if (!$success) {
            return false;
        }
        // 删除当前文件夹 的 子文件夹directory
        foreach($dirList as $dir) {
            $success = $this->cmdDeleteDir($bucketName, $dir['Key']);
            if (!$success) {
                return false;
            }
        }
        // 删除当前文件夹 本身 ./
        if ($prefix !== '') {
            $success = $this->cmdDeleteObjects($bucketName, [$prefix]);
            if (!$success) {
                return false;
            }
        }
        return true;
    }

    // 复制文件
    public function cmdCopyFile(string $srcBucketName, string $srcKey, string $destBucketName, string $destKey) : bool {
        try {
            /**
             * @var Client $client
             * @var \GuzzleHttp\Command\Result $result
             */
            $client = $this->getCosClient($destBucketName);
            $srcConfig = $this->getConfigByBucketName($srcBucketName);
            $srcInfo = [
                'Region' => $bucketConfig['Region'],
                'Bucket' => $bucketConfig['Bucket'],
                'Key' => $srcKey,
            ];
            return (bool) $client->copy($destBucketName, $destKey, $srcInfo);
        } catch (\Exception $exception) {
            return false;
        }
    }

    // 移动或重命名文件
    public function cmdRenameFile(string $srcBucketName, string $srcKey, string $destBucketName, string $destKey) : bool {
        $result = $this->cmdCopyFile($srcBucketName, $srcKey, $destBucketName, $destKey);
        $this->cmdDeleteObjects($srcBucketName, [$srcKey]);

        return $result;
    }

    // 创建文件夹
    public function cmdCreateDir(string $bucketName, string $dirname)
    {
        /**
         * @var Client $client
         * @var \GuzzleHttp\Command\Result $result
         */
        $client = $this->getCosClient($bucketName);
        $result = $this->client->putObject([
            'Bucket' => $bucketName,
            'Key' => trim($dirname, '/').'/',
            'Body' => '',
        ]);
        return $result;
    }
}

