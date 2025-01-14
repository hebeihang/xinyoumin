<?php
namespace app\common\library\helper;

use DirectoryIterator;
use Exception;
use ZipArchive;

class ZipHelper
{
    protected $zip;
    protected $root;
    protected $ignored_names;
    public function __construct(){
        $this->zip = new ZipArchive;
    }
    /**
     * 解压zip文件到指定文件夹
     *
     * @access public
     * @param string $zipFile 压缩文件路径
     * @param string $path  压缩包解压到的目标路径
     * @return bool 解压成功返回 true 否则返回 false
     */
    public function unzip (string $zipFile,string $path): bool
    {
        if ($this->zip->open($zipFile) === true) {
            $file_tmp = @fopen($zipFile, "rb");
            $bin = fread($file_tmp, 15); //只读15字节 各个不同文件类型，头信息不一样。
            fclose($file_tmp);
            /* 只针对zip的压缩包进行处理 */
            if ($result = $this->zip->extractTo($path))
            {
                $this->zip->close();
                return $result;
            }
            else
            {
                return false;
            }
        }
        return false;
    }

    /**
     * 创建压缩文件
     * @access public
     * @param string $zipFile 将要生成的压缩文件路径
     * @param string $folder 将要被压缩的文件夹路径
     * @param null $ignored 要忽略的文件列表
     * @return bool 压缩包生成成功返回true 否则返回 false
     * @throws Exception
     */
    public function zip (string $zipFile,string $folder, $ignored = null): bool
    {
        if(!is_dir(dirname($zipFile)))
        {
            FileHelper::mkDirs(dirname($zipFile));
        }

        if (!is_file($zipFile)) {
            $fp = fopen($zipFile, 'w');
            fclose($fp);
        }
        $this->ignored_names = is_array($ignored) ? $ignored : ($ignored ? array($ignored) : array());
        if ($this->zip->open($zipFile, ZipArchive::OVERWRITE) !== true) {
            throw new Exception("cannot open <$zipFile>\n");
        }
        $folder = substr($folder, -1) == '/' ? substr($folder, 0, strlen($folder)-1) : $folder;
        if(strstr($folder, '/')) {
            $this->root = substr($folder, 0, strrpos($folder, '/')+1);
            $folder = substr($folder, strrpos($folder, '/')+1);
        }
        $this->createZip($folder);
        return $this->zip->close();
    }

    /**
     * 递归添加文件到压缩包
     *
     * @access private
     * @param string $folder 添加到压缩包的文件夹路径
     * @param string|null $parent 添加到压缩包的文件夹上级路径
     * @return void
     */
    private function createZip (string $folder,string $parent=null)
    {
        $full_path = $this->root . $parent . $folder;
        $zip_path = $parent . $folder;
        $this->zip->addEmptyDir($zip_path);
        $dir = new DirectoryIterator($full_path);
        foreach($dir as $file) {
            if(!$file->isDot()) {
                $filename = $file->getFilename();
                if(!in_array($filename, $this->ignored_names)) {
                    if($file->isDir()) {
                        $this->createZip($filename, $zip_path.'/');
                    }else {
                        //第二个参数是重命名文件名,带上路径就可以改变当前文件在压缩包里面的路径.
                        $this->zip->addFile($full_path.'/'.$filename, $zip_path.'/'.$filename);
                    }
                }
            }
        }
    }

    /**
     * 读取压缩包文件与目录列表
     *
     * @access public
     * @param string $zipFile 压缩包文件
     * @return array 文件与目录列表
     */
    public function fileList(string $zipFile): array
    {
        $file_dir_list = array();
        $file_list = array();
        if ($this->zip->open($zipFile) === true) {
            for ($i = 0; $i < $this->zip->numFiles; $i++) {
                $numFiles = $this->zip->getNameIndex($i);
                if (preg_match('/\/$/i', $numFiles))
                {
                    $file_dir_list[] = $numFiles;
                }
                else
                {
                    $file_list[] = $numFiles;
                }
            }
        }
        return array('files'=>$file_list, 'dirs'=>$file_dir_list);
    }

    /**
     * 得到文件头与文件类型映射表
     * @param $bin string 文件的二进制前一段字符
     * @return bool
     */
    private function getTypeList (string $bin): bool
    {
        $array = array(
            array("504B0304", "zip")
        );
        foreach ($array as $v)
        {
            $been = strlen(pack("H*", $v[0])); //得到文件头标记字节数
            $bin = substr($bin, 0, intval($been)); ///需要比较文件头长度
            if(strtolower($v[0]) == strtolower(array_shift(unpack("H*", $bin))))
            {
                return true;
            }
        }
        return false;
    }
}