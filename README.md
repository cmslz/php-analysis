# php-analysis

https://github.com/cmslz/php-analysis

## 介绍
> PHP字符串分词


## 使用

```PHP
$analysis = new \Cmslz\PhpAnalysis\Analysis();
$result = $analysis->run("我爱自然语言处理",3);
var_dump($result);
```