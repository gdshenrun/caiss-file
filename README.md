# caiss-file
caiss-file

UploadRepository的安装 ：

    composer require gdshenrun/caiss-file

思路 :

(1). 存储: 腾讯云的COS, 创建两个bucket, 一个权限是 私有读写, 另一个权限是 公有读, 私有写; 各自独立的加速域名;

(2). 数据库: 只保存URL的路径 userHead/puvYvI7bNVPOVlIbndFc.jpg

(3). 渲染时: 拼接 加速域名 https://{bucket名称}.file.myqcloud.com/

(4). 最终完整链接 https://{bucket名称}.file.myqcloud.com/ + {文件对象Key} + "私有bucket的鉴权参数"

(5). 富文本: 加速域名 使用 占位符 _CDN_DOMAIN_ 存储 和 渲染替换;

(6). 私有bucket 设置 "临时URL" 的有效期为 300秒, 超时则返回 http_status 403;

代码示例:

```
    public function delete(Request $request, UploadRepository $uploadRepository){
        $privateBucket = config('myqcloud.privateBucket');
        $publicBucket = config('myqcloud.publicBucket');
        $dirname = config('myqcloud.resourceDir') . '/2020/';
        try{
            /**
             * @var \Illuminate\Http\UploadedFile $file
             */
            $file = $request->file('img');

            $res = [
                'code' => 200,
                'msg' => 'ok' ,
                'publicFile' => $uploadRepository->uploadFile($publicBucket, $dirname, 'jpg', $file->getPathname()),
                'privateFile' => $uploadRepository->uploadBase64Img($privateBucket, $dirname, $request->post('ba')),
            ];
            $res['publicUrl'] = $uploadRepository->getUrl($publicBucket, $res['publicFile']);
            $res['privateUrl'] = $uploadRepository->getTempUrl($privateBucket, $res['privateFile']);
        } catch (\Exception $e) {
            $res = [
                'code' => 400,
                'msg' => $e->getMessage(),
                'data' => null,
            ];
        }
        return response()->json($res, 200);
    }
```


返回结果

```
{
    "code": 200,
    "msg": "ok",
    "publicFile": "resource/2020/FjKNgiJhxEX30RGaT4HQePEhZ_bD.jpg",
    "privateFile": "resource/2020/Fg4mLEinOrlrPiVXeDVBdpRaRsXz.png",
    "publicUrl": "https://caiss-1301376600.file.myqcloud.com/resource/2020/FjKNgiJhxEX30RGaT4HQePEhZ_bD.jpg",
    "privateUrl": "https://caiss-private-1301376600.file.myqcloud.com/resource/2020/Fg4mLEinOrlrPiVXeDVBdpRaRsXz.png?sign=c85ea828744b128d7a50e40830a432b4&t=1609315291"
}
```

API:

(1). 递归遍历文件夹,返回树形结构

    $dir = config("myqcloud.userHeadDir");
    
    $uploadRepository->cmdList($bucketName, $dir);

(2). 列出文件夹的 文件列表和子文件夹列表(等价于dir,ll,ls命令)

    $uploadRepository->cmdList($bucketName, $dir);

(3) 删除文件夹

    $uploadRepository->cmdDeleteDir($bucketName, $dir);

(4) 删除文件

    $uploadRepository->cmdDeleteObjects($bucketName, $dir);

(5) 复制文件

    $uploadRepository->cmdCopyFile($srcBucketName, $srcKey, $destBucketName, $destKey);

(6) 移动文件 / 文件重命名

    $uploadRepository->cmdRenameFile($srcBucketName, $srcKey, $destBucketName, $destKey);

(7) 创建文件夹

    $uploadRepository->cmdCreateDir($bucketName, $dirname);

(8) 上传base64图片

    $uploadRepository->uploadBase64Img(string $bucketName, string $directory, string $base64Img)

(9) 上传单个文件

    $uploadRepository->uploadFile(string $bucketName, string $directory, string $extension, string $localFilePath)

(10) 私有文件 生成临时链接

    $uploadRepository->getTempUrl(string $bucketName, string $objectKey)

(11) 公共文件 生成访问链接

    $uploadRepository->getUrl(string $bucketName, string $objectKey)