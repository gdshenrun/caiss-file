<?php
/*
qetag 是一个计算文件在七牛云存储上的 hash 值（也是文件下载时的 etag 值）的实用程序。

七牛的 hash/etag 算法是公开的。算法大体如下：

如果你能够确认文件 <= 4M，那么 hash = UrlsafeBase64([0x16, sha1(FileContent)])。也就是，文件的内容的sha1值（20个字节），前面加一个byte（值为0x16），构成 21 字节的二进制数据，然后对这 21 字节的数据做 urlsafe 的 base64 编码。
如果文件 > 4M，则 hash = UrlsafeBase64([0x96, sha1([sha1(Block1), sha1(Block2), ...])])，其中 Block 是把文件内容切分为 4M 为单位的一个个块，也就是 BlockI = FileContent[I*4M:(I+1)*4M]。
为何需要公开 hash/etag 算法？这个和 “消重” 问题有关，详细见：

https://developer.qiniu.com/kodo/kb/1365/how-to-avoid-the-users-to-upload-files-with-the-same-key
http://segmentfault.com/q/1010000000315810
为何在 sha1 值前面加一个byte的标记位(0x16或0x96）？

0x16 = 22，而 2^22 = 4M。所以前面的 0x16 其实是文件按 4M 分块的意思。
0x96 = 0x80 | 0x16。其中的 0x80 表示这个文件是大文件（有多个分块），hash 值也经过了2重的 sha1 计算。
*/


namespace GdShenrun\Caiss\File;


final class Etag
{
    const BLOCK_SIZE = 4194304; // 1 << 22, 4*1024*1024 分块上传块大小，该参数为接口规格，不能修改

    private static function packArray($v, $a)
    {
        return call_user_func_array('pack', array_merge(array($v), (array)$a));
    }

    private static function blockCount($fsize)
    {
        return intval(($fsize + (self::BLOCK_SIZE - 1)) / self::BLOCK_SIZE);
    }

    private static function calcSha1($data)
    {
        $sha1Str = sha1($data, true);
        $err = error_get_last();
        if ($err !== null) {
            return array(null, $err);
        }
        $byteArray = unpack('C*', $sha1Str);
        return array($byteArray, null);
    }


    final public static function sum(string $filename) : array
    {
        if (!is_file($filename)) {
            $err = array ('message' => 'Can not open ' . $filename . ' as a file.');
            return array(null, $err);
        }
        $fhandler = fopen($filename, 'r');
        $err = error_get_last();
        if ($err !== null) {
            return array(null, $err);
        }

        $fstat = fstat($fhandler);
        $fsize = $fstat['size'];
        if ((int)$fsize === 0) {
            fclose($fhandler);
            return array('Fto5o-5ea0sNMlW_75VgGJCv2AcJ', null);
        }
        $blockCnt = self::blockCount($fsize);
        $sha1Buf = array();

        if ($blockCnt <= 1) {
            array_push($sha1Buf, 0x16);
            $fdata = fread($fhandler, self::BLOCK_SIZE);
            list($sha1Code,$err) = self::calcSha1($fdata);
            if ($err !== null) {
                fclose($fhandler);
                return array(null, $err);
            }
            $sha1Buf = array_merge($sha1Buf, $sha1Code);
        } else {
            array_push($sha1Buf, 0x96);
            $sha1BlockBuf = array();
            for ($i = 0; $i < $blockCnt; $i++) {
                $fdata = fread($fhandler, self::BLOCK_SIZE);
                list($sha1Code, $err) = self::calcSha1($fdata);
                if ($err !== null) {
                    fclose($fhandler);
                    return array(null, $err);
                }
                $sha1BlockBuf = array_merge($sha1BlockBuf, $sha1Code);
            }
            $tmpData = self::packArray('C*', $sha1BlockBuf);
            list($sha1Final, $err) = self::calcSha1($tmpData);
            if ($err !== null) {
                fclose($fhandler);
                return array(null, $err);
            }
            $sha1Buf = array_merge($sha1Buf, $sha1Final);
        }
        $etag = self::base64_urlSafeEncode(self::packArray('C*', $sha1Buf));
        fclose($fhandler);
        return array($etag, null);
    }

    /**
     * 计算文件的crc32检验码:
     *
     * @param $file string  待计算校验码的文件路径
     *
     * @return string 文件内容的crc32校验码
     */
    final public static function crc32_file($file)
    {
        $hash = hash_file('crc32b', $file);
        $array = unpack('N', pack('H*', $hash));
        return sprintf('%u', $array[1]);
    }

    /**
     * 计算输入流的crc32检验码
     *
     * @param $data 待计算校验码的字符串
     *
     * @return string 输入字符串的crc32校验码
     */
    final public static function crc32_data($data)
    {
        $hash = hash('crc32b', $data);
        $array = unpack('N', pack('H*', $hash));
        return sprintf('%u', $array[1]);
    }

    /**
     * 对提供的数据进行urlsafe的base64编码。
     *
     * @param string $data 待编码的数据，一般为字符串
     *
     * @return string 编码后的字符串
     * @link http://developer.qiniu.com/docs/v6/api/overview/appendix.html#urlsafe-base64
     */
    final public static function base64_urlSafeEncode($data)
    {
        $find = array('+', '/');
        $replace = array('-', '_');
        return str_replace($find, $replace, base64_encode($data));
    }

    /**
     * 对提供的urlsafe的base64编码的数据进行解码
     *
     * @param string $str 待解码的数据，一般为字符串
     *
     * @return string 解码后的字符串
     */
    final public static function base64_urlSafeDecode($str)
    {
        $find = array('-', '_');
        $replace = array('+', '/');
        return base64_decode(str_replace($find, $replace, $str));
    }
}


