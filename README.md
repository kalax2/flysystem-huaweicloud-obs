## 简介
华为云对象存储服务OBS Flysystem Adapter

## 使用方法
安装
```shell
composer require kalax2/flysystem-huaweicloud-obs
```
示例
```php
use Kalax2\Flysystem\Obs\ObsAdapter;
use League\Flysystem\Filesystem;


$adapter = new ObsAdapter(
    // Access Key
    accessKey: 'AccessKey', 
    // Secret Key
    secretKey: 'SecretKey', 
    // 地域，注意不是控制台的Endpoint域名
    // 请看地域列表：https://console.huaweicloud.com/apiexplorer/#/endpoint/OBS
    region: 'cn-north-1', 
    // 存储桶名称
    bucket: 'BucketName',
    // 额外配置，可选
    config: [
        'guzzle' => []  // GuzzleHttp 配置
        'ssl' => true  // 是否使用https,只影响temporaryUrl()和getUrl()返回的Url
        'domain' => 'abc.example.com'  // 自定义域名
    ]
);

$filesystem = new Filesystem($adapter);

$filesystem->write('hello/world.txt', 'Hello, World!');
$filesystem->move('hello/world.txt', 'hello world.txt');
$filesystem->fileExists('hello/world.txt');
$filesystem->setVisibility('hello/world.txt', Visibility::PUBLIC);
```
更多信息请参考 [Flysystem 官方文档](https://flysystem.thephpleague.com/docs/)