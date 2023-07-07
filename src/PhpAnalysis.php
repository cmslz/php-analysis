<?php
/**
 * Each engineer has a duty to keep the code elegant
 * Created by xiaobai at 2023/7/7 17:06
 */

namespace Cmslz\PhpAnalysis;


//常量定义
define('_SP_', chr(0xFF) . chr(0xFE));
define('UCS2', 'ucs-2be');

class PhpAnalysis
{

    //hash算法选项
    public int $maskValue = 0xFFFF;

    //输入和输出的字符编码（只允许 utf-8、gbk/gb2312/gb18030、big5 三种类型）
    public string $sourceCharSet = 'utf-8';
    public string $targetCharSet = 'utf-8';

    //生成的分词结果数据类型 1 为全部， 2为 词典词汇及单个中日韩简繁字符及英文， 3 为词典词汇及英文
    public int $resultType = 1;

    //句子长度小于这个数值时不拆分，notSplitLen = n(个汉字) * 2 + 1
    public int $notSplitLen = 5;

    //把英文单词全部转小写
    public bool $toLower = false;

    //使用最大切分模式对二元词进行消岐
    public bool $differMax = false;

    //尝试合并单字
    public bool $unitWord = true;

    //初始化类时直接加载词典
    public static bool $loadInit = true;

    //被转换为unicode的源字符串
    private string $sourceString = '';

    //附加词典
    public array $addonDic = [];
    public string $addonDicFile = 'dict/words_addons.dic';

    //主词典
    public string $dicStr = '';
    public array $mainDic = [];
    public $mainDicHand;
    public array $mainDicInfos = [];
    public string $mainDicFile = 'dict/base_dic_full.dic';
    //是否直接载入词典（选是载入速度较慢，但解析较快；选否载入较快，但解析较慢，需要时才会载入特定的词条）
    protected bool $isLoadAll = false;

    //主词典词语最大长度 x / 2
    private int $dicWordMax = 14;
    //粗分后的数组（通常是截取句子等用途）
    private array $simpleResult = [];
    //最终结果(用空格分开的词汇列表)
    protected array $finallyResult = [];

    //是否已经载入词典
    public bool $isLoadDic = false;
    //系统识别或合并的新词
    public array $newWords = [];
    public string $foundWordStr = '';
    //词库载入时间
    public int $loadTime = 0;
    protected array $finallyIndex;

    /**
     * 构造函数
     * @param string $sourceCharset
     * @param string $targetCharset
     * @param bool $load_all
     * @param string $source
     *
     */
    public function __construct(
        string $sourceCharset = 'utf-8',
        string $targetCharset = 'utf-8',
        bool $load_all = true,
        string $source = ''
    ) {
        $this->addonDicFile = dirname(__FILE__) . '/' . $this->addonDicFile;
        $this->mainDicFile = dirname(__FILE__) . '/' . $this->mainDicFile;
        $this->setSource($source, $sourceCharset, $targetCharset);
        $this->isLoadAll = $load_all;
        if (self::$loadInit) {
            $this->loadDict();
        }
    }

    /**
     * 析构函数
     */
    function __destruct()
    {
        if ($this->mainDicHand !== false) {
            @fclose($this->mainDicHand);
        }
    }

    /**
     * 根据字符串计算key索引
     * @param $key
     * @return int
     */
    private function _getIndex($key): int
    {
        $l = strlen($key);
        $h = 0x238f13af;
        while ($l--) {
            $h += ($h << 5);
            $h ^= ord($key[$l]);
            $h &= 0x7fffffff;
        }
        return ($h % $this->maskValue);
    }

    /**
     * 从文件获得词
     * @param $key
     * @param string $type (类型 word 或 key_groups)
     * @return bool|int|array
     */
    protected function getWordInfos($key, string $type = 'word'): bool|int|array
    {
        if (!$this->mainDicHand) {
            $this->mainDicHand = fopen($this->mainDicFile, 'r');
        }
        $keyNum = $this->_getIndex($key);
        if (isset($this->mainDicInfos[$keyNum])) {
            $data = $this->mainDicInfos[$keyNum];
        } else {
            $move_pos = $keyNum * 8;
            fseek($this->mainDicHand, $move_pos, SEEK_SET);
            $dat = fread($this->mainDicHand, 8);
            $arr = unpack('I1s/n1l/n1c', $dat);
            if ($arr['l'] == 0) {
                return false;
            }
            fseek($this->mainDicHand, $arr['s'], SEEK_SET);
            $data = @unserialize(fread($this->mainDicHand, $arr['l']));
            $this->mainDicInfos[$keyNum] = $data;
        }
        if (!is_array($data) || !isset($data[$key])) {
            return false;
        }
        return ($type == 'word' ? $data[$key] : $data);
    }

    /**
     * 设置源字符串
     * @param $source
     * @param string $sourceCharset
     * @param string $targetCharset
     *
     * @return bool
     */
    public function setSource($source, string $sourceCharset = 'utf-8', string $targetCharset = 'utf-8')
    {
        $this->sourceCharSet = strtolower($sourceCharset);
        $this->targetCharSet = strtolower($targetCharset);
        $this->simpleResult = [];
        $this->finallyResult = [];
        $this->finallyIndex = [];
        if ($source != '') {
            $rs = true;
            if (preg_match("/^utf/", $sourceCharset)) {
                $this->sourceString = iconv('utf-8', UCS2, $source);
            } else {
                if (preg_match("/^gb/", $sourceCharset)) {
                    $this->sourceString = iconv('utf-8', UCS2, iconv('gb18030', 'utf-8', $source));
                } else {
                    if (preg_match("/^big/", $sourceCharset)) {
                        $this->sourceString = iconv('utf-8', UCS2, iconv('big5', 'utf-8', $source));
                    } else {
                        $rs = false;
                    }
                }
            }
        } else {
            $rs = false;
        }
        return $rs;
    }

    /**
     * 设置结果类型(只在获取finallyResult才有效)
     * @param $rsType 1 为全部， 2去除特殊符号
     *
     * @return void
     */
    public function setResultType($rsType)
    {
        $this->resultType = $rsType;
    }

    /**
     * 载入词典
     * @return void
     */
    public function loadDict($mainDic = '')
    {
        $startT = microtime(true);
        //正常读取文件
        $dicAddon = $this->addonDicFile;
        if ($mainDic == '' || !file_exists($mainDic)) {
            $dicWords = $this->mainDicFile;
        } else {
            $dicWords = $mainDic;
            $this->mainDicFile = $mainDic;
        }

        //加载主词典（只打开）
        $this->mainDicHand = fopen($dicWords, 'r');

        //载入副词典
        $hw = '';
        $ds = file($dicAddon);
        foreach ($ds as $d) {
            $d = trim($d);
            if ($d == '') {
                continue;
            }
            $estr = substr($d, 1, 1);
            if ($estr == ':') {
                $hw = substr($d, 0, 1);
            } else {
                $spstr = _SP_;
                $spstr = iconv(UCS2, 'utf-8', $spstr);
                $ws = explode(',', $d);
                $wall = iconv('utf-8', UCS2, join($spstr, $ws));
                $ws = explode(_SP_, $wall);
                foreach ($ws as $estr) {
                    $this->addonDic[$hw][$estr] = strlen($estr);
                }
            }
        }
        $this->loadTime = microtime(true) - $startT;
        $this->isLoadDic = true;
    }

    /**
     * 检测某个词是否存在
     */
    public function isWord($word): bool
    {
        $winFos = $this->getWordInfos($word);
        return ($winFos !== false);
    }

    /**
     * 获得某个词的词性及词频信息
     * @parem $word unicode编码的词
     * @param $word
     * @return string
     */
    public function getWordProperty($word): string
    {
        if (strlen($word) < 4) {
            return '/s';
        }
        $infos = $this->getWordInfos($word);
        return isset($infos[1]) ? "/{$infos[1]}{$infos[0]}" : "/s";
    }

    /**
     * 指定某词的词性信息（通常是新词）
     * @parem $word unicode编码的词
     * @parem $infos array('c' => 词频, 'm' => 词性);
     * @return void;
     */
    public function setWordInfos($word, $infos)
    {
        if (strlen($word) < 4) {
            return;
        }
        if (isset($this->mainDicInfos[$word])) {
            $this->newWords[$word]++;
            $this->mainDicInfos[$word]['c']++;
        } else {
            $this->newWords[$word] = 1;
            $this->mainDicInfos[$word] = $infos;
        }
    }

    /**
     * 开始执行分析
     * @parem bool optimize 是否对结果进行优化
     * @param bool $optimize
     */
    public function startAnalysis(bool $optimize = true)
    {
        if (!$this->isLoadDic) {
            $this->loadDict();
        }
        $this->simpleResult = $this->finallyResult = [];
        $this->sourceString .= chr(0) . chr(32);
        $sLen = strlen($this->sourceString);
        $sbcArr = [];
        $j = 0;
        //全角与半角字符对照表
        for ($i = 0xFF00; $i < 0xFF5F; $i++) {
            $scb = 0x20 + $j;
            $j++;
            $sbcArr[$i] = $scb;
        }
        //对字符串进行粗分
        $onStr = '';
        $lastC = 1; //1 中/韩/日文, 2 英文/数字/符号('.', '@', '#', '+'), 3 ANSI符号 4 纯数字 5 非ANSI符号或不支持字符
        $s = 0;
        $ansiWordMatch = "[0-9a-z@#%\+\.-]";
        $notNumberMatch = "[a-z@#%\+]";
        for ($i = 0; $i < $sLen; $i++) {
            $c = $this->sourceString[$i] . $this->sourceString[++$i];
            $cn = hexdec(bin2hex($c));
            $cn = $sbcArr[$cn] ?? $cn;
            //ANSI字符
            if ($cn < 0x80) {
                if (preg_match('/' . $ansiWordMatch . '/i', chr($cn))) {
                    if ($lastC != 2 && $onStr != '') {
                        $this->simpleResult[$s]['w'] = $onStr;
                        $this->simpleResult[$s]['t'] = $lastC;
                        $this->_deepAnalysis($onStr, $lastC, $s, $optimize);
                        $s++;
                        $onStr = '';
                    }
                    $lastC = 2;
                    $onStr .= chr(0) . chr($cn);
                } else {
                    if ($onStr != '') {
                        $this->simpleResult[$s]['w'] = $onStr;
                        if ($lastC == 2) {
                            if (!preg_match('/' . $notNumberMatch . '/i', iconv(UCS2, 'utf-8', $onStr))) {
                                $lastC = 4;
                            }
                        }
                        $this->simpleResult[$s]['t'] = $lastC;
                        if ($lastC != 4) {
                            $this->_deepAnalysis($onStr, $lastC, $s, $optimize);
                        }
                        $s++;
                    }
                    $onStr = '';
                    $lastC = 3;
                    if ($cn < 31) {
                        continue;
                    } else {
                        $this->simpleResult[$s]['w'] = chr(0) . chr($cn);
                        $this->simpleResult[$s]['t'] = 3;
                        $s++;
                    }
                }
            } //普通字符
            else {
                //正常文字
                if (($cn > 0x3FFF && $cn < 0x9FA6) || ($cn > 0xF8FF && $cn < 0xFA2D)
                    || ($cn > 0xABFF && $cn < 0xD7A4) || ($cn > 0x3040 && $cn < 0x312B)) {
                    if ($lastC != 1 && $onStr != '') {
                        $this->simpleResult[$s]['w'] = $onStr;
                        if ($lastC == 2) {
                            if (!preg_match('/' . $notNumberMatch . '/i', iconv(UCS2, 'utf-8', $onStr))) {
                                $lastC = 4;
                            }
                        }
                        $this->simpleResult[$s]['t'] = $lastC;
                        if ($lastC != 4) {
                            $this->_deepAnalysis($onStr, $lastC, $s, $optimize);
                        }
                        $s++;
                        $onStr = '';
                    }
                    $lastC = 1;
                    $onStr .= $c;
                } //特殊符号
                else {
                    if ($onStr != '') {
                        $this->simpleResult[$s]['w'] = $onStr;
                        if ($lastC == 2) {
                            if (!preg_match('/' . $notNumberMatch . '/i', iconv(UCS2, 'utf-8', $onStr))) {
                                $lastC = 4;
                            }
                        }
                        $this->simpleResult[$s]['t'] = $lastC;
                        if ($lastC != 4) {
                            $this->_deepAnalysis($onStr, $lastC, $s, $optimize);
                        }
                        $s++;
                    }

                    //检测书名
                    if ($cn == 0x300A) {
                        $tmpW = '';
                        $n = 1;
                        $isOk = false;
                        $ew = chr(0x30) . chr(0x0B);
                        while (true) {
                            if (!isset($this->sourceString[$i + $n + 1])) {
                                break;
                            }
                            $w = $this->sourceString[$i + $n] . $this->sourceString[$i + $n + 1];
                            if ($w == $ew) {
                                $this->simpleResult[$s]['w'] = $c;
                                $this->simpleResult[$s]['t'] = 5;
                                $s++;

                                $this->simpleResult[$s]['w'] = $tmpW;
                                $this->newWords[$tmpW] = 1;
                                if (!isset($this->newWords[$tmpW])) {
                                    $this->foundWordStr .= $this->_outStringEncoding($tmpW) . '/nb, ';
                                    $this->setWordInfos($tmpW, array('c' => 1, 'm' => 'nb'));
                                }
                                $this->simpleResult[$s]['t'] = 13;

                                $s++;

                                //最大切分模式对书名继续分词
                                if ($this->differMax) {
                                    $this->simpleResult[$s]['w'] = $tmpW;
                                    $this->simpleResult[$s]['t'] = 21;
                                    $this->_deepAnalysis($tmpW, $lastC, $s, $optimize);
                                    $s++;
                                }

                                $this->simpleResult[$s]['w'] = $ew;
                                $this->simpleResult[$s]['t'] = 5;
                                $s++;

                                $i = $i + $n + 1;
                                $isOk = true;
                                $onStr = '';
                                $lastC = 5;
                                break;
                            } else {
                                $n = $n + 2;
                                $tmpW .= $w;
                                if (strlen($tmpW) > 60) {
                                    break;
                                }
                            }
                        }//while
                        if (!$isOk) {
                            $this->simpleResult[$s]['w'] = $c;
                            $this->simpleResult[$s]['t'] = 5;
                            $s++;
                            $onStr = '';
                            $lastC = 5;
                        }
                        continue;
                    }

                    $onStr = '';
                    $lastC = 5;
                    if ($cn == 0x3000) {
                        continue;
                    } else {
                        $this->simpleResult[$s]['w'] = $c;
                        $this->simpleResult[$s]['t'] = 5;
                        $s++;
                    }
                }//2byte symbol

            }//end 2byte char

        }//end for

        //处理分词后的结果
        $this->_sortFinallyResult();
    }

    /**
     * 深入分词
     * @parem $str
     * @parem $ctype (2 英文类， 3 中/韩/日文类)
     * @parem $spos   当前粗分结果游标
     * @param $str
     * @param $cType
     * @param $sPos
     * @param bool $optimize
     */
    private function _deepAnalysis(&$str, $cType, $sPos, bool $optimize = true)
    {
        //中文句子
        if ($cType == 1) {
            $slen = strlen($str);
            //小于系统配置分词要求长度的句子
            if ($slen < $this->notSplitLen) {
                $lastType = 0;
                if ($sPos > 0) {
                    $lastType = $this->simpleResult[$sPos - 1]['t'];
                }
                if ($slen < 5) {
                    if ($lastType == 4 && (isset($this->addonDic['u'][$str]) || isset($this->addonDic['u'][substr($str,
                                    0, 2)]))) {
                        $str2 = '';
                        if (!isset($this->addonDic['u'][$str]) && isset($this->addonDic['s'][substr($str, 2, 2)])) {
                            $str2 = substr($str, 2, 2);
                            $str = substr($str, 0, 2);
                        }
                        $ww = $this->simpleResult[$sPos - 1]['w'] . $str;
                        $this->simpleResult[$sPos - 1]['w'] = $ww;
                        $this->simpleResult[$sPos - 1]['t'] = 4;
                        if (!isset($this->newWords[$this->simpleResult[$sPos - 1]['w']])) {
                            $this->foundWordStr .= $this->_outStringEncoding($ww) . '/mu, ';
                            $this->setWordInfos($ww, array('c' => 1, 'm' => 'mu'));
                        }
                        $this->simpleResult[$sPos]['w'] = '';
                        if ($str2 != '') {
                            $this->finallyResult[$sPos - 1][] = $ww;
                            $this->finallyResult[$sPos - 1][] = $str2;
                        }
                    } else {
                        $this->finallyResult[$sPos][] = $str;
                    }
                } else {
                    $this->_deepAnalysisCn($str, $sPos, $slen, $optimize);
                }
            } //正常长度的句子，循环进行分词处理
            else {
                $this->_deepAnalysisCn($str, $sPos, $slen, $optimize);
            }
        } //英文句子，转为小写
        else {
            if ($this->toLower) {
                $this->finallyResult[$sPos][] = strtolower($str);
            } else {
                $this->finallyResult[$sPos][] = $str;
            }
        }
    }

    /**
     * 中文的深入分词
     * @parem $str
     * @return void
     */
    private function _deepAnalysisCn(&$str, $spos, $slen, $optimize = true)
    {
        $quote1 = chr(0x20) . chr(0x1C);
        $tmpArr = [];
        //如果前一个词为 “ ， 并且字符串小于3个字符当成一个词处理。
        if ($spos > 0 && $slen < 11 && $this->simpleResult[$spos - 1]['w'] == $quote1) {
            $tmpArr[] = $str;
            if (!isset($this->newWords[$str])) {
                $this->foundWordStr .= $this->_outStringEncoding($str) . '/nq, ';
                $this->setWordInfos($str, array('c' => 1, 'm' => 'nq'));
            }
            if (!$this->differMax) {
                $this->finallyResult[$spos][] = $str;
                return;
            }
        }
        //进行切分
        for ($i = $slen - 1; $i > 0; $i -= 2) {
            //单个词
            $nc = $str[$i - 1] . $str[$i];
            //是否已经到最后两个字
            if ($i <= 2) {
                $tmpArr[] = $nc;
                break;
            }
            $isOk = false;
            $i = $i + 1;
            for ($k = $this->dicWordMax; $k > 1; $k = $k - 2) {
                if ($i < $k) {
                    continue;
                }
                $w = substr($str, $i - $k, $k);
                if (strlen($w) <= 2) {
                    $i = $i - 1;
                    break;
                }
                if ($this->isWord($w)) {
                    $tmpArr[] = $w;
                    $i = $i - $k + 1;
                    $isOk = true;
                    break;
                }
            }
            //没适合词
            if (!$isOk) {
                $tmpArr[] = $nc;
            }
        }
        $wCount = count($tmpArr);
        if ($wCount == 0) {
            return;
        }
        $this->finallyResult[$spos] = array_reverse($tmpArr);
        //优化结果(岐义处理、新词、数词、人名识别等)
        if ($optimize) {
            $this->_optimizeResult($this->finallyResult[$spos], $spos);
        }
    }

    /**
     * 对最终分词结果进行优化（把simpleresult结果合并，并尝试新词识别、数词合并等）
     * @parem $optimize 是否优化合并的结果
     * @param $smArr
     * @param $sPos
     * @return void
     */
    //t = 1 中/韩/日文, 2 英文/数字/符号('.', '@', '#', '+'), 3 ANSI符号 4 纯数字 5 非ANSI符号或不支持字符
    private function _optimizeResult(&$smArr, $sPos): void
    {
        $newArr = [];
        $prePos = $sPos - 1;
        $arlen = count($smArr);
        $i = $j = 0;
        //检测数量词
        if ($prePos > -1 && !isset($this->finallyResult[$prePos])) {
            $lastW = $this->simpleResult[$prePos]['w'];
            $lastT = $this->simpleResult[$prePos]['t'];
            if (($lastT == 4 || isset($this->addonDic['c'][$lastW])) && isset($this->addonDic['u'][$smArr[0]])) {
                $this->simpleResult[$prePos]['w'] = $lastW . $smArr[0];
                $this->simpleResult[$prePos]['t'] = 4;
                if (!isset($this->newWords[$this->simpleResult[$prePos]['w']])) {
                    $this->foundWordStr .= $this->_outStringEncoding($this->simpleResult[$prePos]['w']) . '/mu, ';
                    $this->setWordInfos($this->simpleResult[$prePos]['w'], ['c' => 1, 'm' => 'mu']);
                }
                $smArr[0] = '';
                $i++;
            }
        }
        for (; $i < $arlen; $i++) {
            if (!isset($smArr[$i + 1])) {
                $newArr[$j] = $smArr[$i];
                break;
            }
            $cw = $smArr[$i];
            $nw = $smArr[$i + 1];
            $isCheck = false;
            //检测数量词
            if (isset($this->addonDic['c'][$cw]) && isset($this->addonDic['u'][$nw])) {
                //最大切分时保留合并前的词
                if ($this->differMax) {
                    $newArr[$j] = chr(0) . chr(0x28);
                    $j++;
                    $newArr[$j] = $cw;
                    $j++;
                    $newArr[$j] = $nw;
                    $j++;
                    $newArr[$j] = chr(0) . chr(0x29);
                    $j++;
                }
                $newArr[$j] = $cw . $nw;
                if (!isset($this->newWords[$newArr[$j]])) {
                    $this->foundWordStr .= $this->_outStringEncoding($newArr[$j]) . '/mu, ';
                    $this->setWordInfos($newArr[$j], array('c' => 1, 'm' => 'mu'));
                }
                $j++;
                $i++;
                $isCheck = true;
            } //检测前导词(通常是姓)
            else {
                if (isset($this->addonDic['n'][$smArr[$i]])) {
                    $is_rs = false;
                    //词语是副词或介词或频率很高的词不作为人名
                    if (strlen($nw) == 4) {
                        $winFos = $this->getWordInfos($nw);
                        if (isset($winFos['m']) && ($winFos['m'] == 'r' || $winFos['m'] == 'c' || $winFos['c'] > 500)) {
                            $is_rs = true;
                        }
                    }
                    if (!isset($this->addonDic['s'][$nw]) && strlen($nw) < 5 && !$is_rs) {
                        $newArr[$j] = $cw . $nw;
                        //尝试检测第三个词
                        if (strlen($nw) == 2 && isset($smArr[$i + 2]) && strlen($smArr[$i + 2]) == 2 && !isset($this->addonDic['s'][$smArr[$i + 2]])) {
                            $newArr[$j] .= $smArr[$i + 2];
                            $i++;
                        }
                        if (!isset($this->newWords[$newArr[$j]])) {
                            $this->setWordInfos($newArr[$j], array('c' => 1, 'm' => 'nr'));
                            $this->foundWordStr .= $this->_outStringEncoding($newArr[$j]) . '/nr, ';
                        }
                        //为了防止错误，保留合并前的姓名
                        if (strlen($nw) == 4) {
                            $j++;
                            $newArr[$j] = chr(0) . chr(0x28);
                            $j++;
                            $newArr[$j] = $cw;
                            $j++;
                            $newArr[$j] = $nw;
                            $j++;
                            $newArr[$j] = chr(0) . chr(0x29);
                        }

                        $j++;
                        $i++;
                        $isCheck = true;
                    }
                } //检测后缀词(地名等)
                else {
                    if (isset($this->addonDic['a'][$nw])) {
                        $is_rs = false;
                        //词语是副词或介词不作为前缀
                        if (strlen($cw) > 2) {
                            $winFos = $this->getWordInfos($cw);
                            if (isset($winFos['m']) && ($winFos['m'] == 'a' || $winFos['m'] == 'r' || $winFos['m'] == 'c' || $winFos['c'] > 500)) {
                                $is_rs = true;
                            }
                        }
                        if (!isset($this->addonDic['s'][$cw]) && !$is_rs) {
                            $newArr[$j] = $cw . $nw;
                            if (!isset($this->newWords[$newArr[$j]])) {
                                $this->foundWordStr .= $this->_outStringEncoding($newArr[$j]) . '/na, ';
                                $this->setWordInfos($newArr[$j], array('c' => 1, 'm' => 'na'));
                            }
                            $i++;
                            $j++;
                            $isCheck = true;
                        }
                    } //新词识别（暂无规则）
                    else {
                        if ($this->unitWord) {
                            if (strlen($cw) == 2 && strlen($nw) == 2
                                && !isset($this->addonDic['s'][$cw]) && !isset($this->addonDic['t'][$cw]) && !isset($this->addonDic['a'][$cw])
                                && !isset($this->addonDic['s'][$nw]) && !isset($this->addonDic['c'][$nw])) {
                                $newArr[$j] = $cw . $nw;
                                //尝试检测第三个词
                                if (isset($smArr[$i + 2]) && strlen($smArr[$i + 2]) == 2 && (isset($this->addonDic['a'][$smArr[$i + 2]]) || isset($this->addonDic['u'][$smArr[$i + 2]]))) {
                                    $newArr[$j] .= $smArr[$i + 2];
                                    $i++;
                                }
                                if (!isset($this->newWords[$newArr[$j]])) {
                                    $this->foundWordStr .= $this->_outStringEncoding($newArr[$j]) . '/ms, ';
                                    $this->setWordInfos($newArr[$j], array('c' => 1, 'm' => 'ms'));
                                }
                                $i++;
                                $j++;
                                $isCheck = true;
                            }
                        }
                    }
                }
            }

            //不符合规则
            if (!$isCheck) {
                $newArr[$j] = $cw;
                //二元消岐处理——最大切分模式
                if ($this->differMax && !isset($this->addonDic['s'][$cw]) && strlen($cw) < 5 && strlen($nw) < 7) {
                    $sLen = strlen($nw);
                    for ($y = 2; $y <= $sLen - 2; $y = $y + 2) {
                        $nHead = substr($nw, $y - 2, 2);
                        $nFont = $cw . substr($nw, 0, $y - 2);
                        if ($this->isWord($nFont . $nHead)) {
                            if (strlen($cw) > 2) {
                                $j++;
                            }
                            $newArr[$j] = $nFont . $nHead;
                        }
                    }
                }
                $j++;
            }
        }
        $smArr = $newArr;
    }

    /**
     * 转换最终分词结果到 finallyResult 数组
     * @return void
     */
    private function _sortFinallyResult()
    {
        $newArr = [];
        $i = 0;
        foreach ($this->simpleResult as $k => $v) {
            if (empty($v['w'])) {
                continue;
            }
            if (isset($this->finallyResult[$k]) && count($this->finallyResult[$k]) > 0) {
                foreach ($this->finallyResult[$k] as $w) {
                    if (!empty($w)) {
                        $newArr[$i]['w'] = $w;
                        $newArr[$i]['t'] = 20;
                        $i++;
                    }
                }
            } else {
                if ($v['t'] != 21) {
                    $newArr[$i]['w'] = $v['w'];
                    $newArr[$i]['t'] = $v['t'];
                    $i++;
                }
            }
        }
        $this->finallyResult = $newArr;
    }

    /**
     * 把uncode字符串转换为输出字符串
     * @parem str
     * return string
     */
    protected function _outStringEncoding(&$str): bool|string
    {
        $rsc = $this->_sourceResultCharset();
        if ($rsc == 1) {
            $rsStr = iconv(UCS2, 'utf-8', $str);
        } else {
            if ($rsc == 2) {
                $rsStr = iconv('utf-8', 'gb18030', iconv(UCS2, 'utf-8', $str));
            } else {
                $rsStr = iconv('utf-8', 'big5', iconv(UCS2, 'utf-8', $str));
            }
        }
        return $rsStr;
    }

    /**
     * 获取最终结果字符串（用空格分开后的分词结果）
     * @param string $spWord
     * @param bool $wordMeanings
     * @return string
     */
    public function getFinallyResult(string $spWord = ' ', bool $wordMeanings = false): string
    {
        $rsStr = '';
        foreach ($this->finallyResult as $v) {
            if ($this->resultType == 2 && ($v['t'] == 3 || $v['t'] == 5)) {
                continue;
            }
            $m = '';
            if ($wordMeanings) {
                $m = $this->getWordProperty($v['w']);
            }
            $w = $this->_outStringEncoding($v['w']);
            if ($w != ' ') {
                if ($wordMeanings) {
                    $rsStr .= $spWord . $w . $m;
                } else {
                    $rsStr .= $spWord . $w;
                }
            }
        }
        return $rsStr;
    }

    /**
     * 获取粗分结果，不包含粗分属性
     */
    public function getSimpleResult(): array
    {
        $reArr = [];
        foreach ($this->simpleResult as $v) {
            if (empty($v['w'])) {
                continue;
            }
            $w = $this->_outStringEncoding($v['w']);
            if ($w != ' ') {
                $reArr[] = $w;
            }
        }
        return $reArr;
    }

    /**
     * 获取粗分结果，包含粗分属性（1中文词句、2 ANSI词汇（包括全角），3 ANSI标点符号（包括全角），4数字（包括全角），5 中文标点或无法识别字符）
     */
    public function getSimpleResultAll(): array
    {
        $reArr = [];
        foreach ($this->simpleResult as $k => $v) {
            $w = $this->_outStringEncoding($v['w']);
            if ($w != ' ') {
                $reArr[$k]['w'] = $w;
                $reArr[$k]['t'] = $v['t'];
            }
        }
        return $reArr;
    }

    /**
     * 获取索引hash数组
     * @return array('word'=>count,...)
     */
    public function getFinallyIndex(): array
    {
        $reArr = [];
        foreach ($this->finallyResult as $v) {
            if ($this->resultType == 2 && ($v['t'] == 3 || $v['t'] == 5)) {
                continue;
            }
            $w = $this->_outStringEncoding($v['w']);
            if ($w == ' ') {
                continue;
            }
            if (isset($reArr[$w])) {
                $reArr[$w]++;
            } else {
                $reArr[$w] = 1;
            }
        }
        arsort($reArr);
        return $reArr;
    }

    /**
     * 获取最终关键字(返回用 "," 间隔的关键字)
     * @param int $num
     * @return string
     */
    public function getFinallyKeywords(int $num = 10): string
    {
        $n = 0;
        $arr = $this->getFinallyIndex();
        $okStr = '';
        foreach ($arr as $k => $v) {
            //排除长度为1的词
            if (strlen($k) == 1) {
                continue;
            } //排除长度为2的非英文词
            elseif (strlen($k) == 2 && preg_match('/[^0-9a-zA-Z]/', $k)) {
                continue;
            } //排除单个中文字
            elseif (strlen($k) < 4 && !preg_match('/[a-zA-Z]/', $k)) {
                continue;
            }
            $okStr .= ($okStr == '' ? $k : ',' . $k);
            $n++;
            if ($n > $num) {
                break;
            }
        }
        return $okStr;
    }

    /**
     * 获得保存目标编码
     * @return int
     */
    private function _sourceResultCharset(): int
    {
        if (preg_match("/^utf/", $this->targetCharSet)) {
            $rs = 1;
        } else {
            if (preg_match("/^gb/", $this->targetCharSet)) {
                $rs = 2;
            } else {
                if (preg_match("/^big/", $this->targetCharSet)) {
                    $rs = 3;
                } else {
                    $rs = 4;
                }
            }
        }
        return $rs;
    }

    /**
     * 编译词典
     * @parem $sourceFile utf-8编码的文本词典数据文件<参见范例dict/not-build/base_dic_full.txt>
     * 注意, 需要PHP开放足够的内存才能完成操作
     * @return void
     */
    public function makeDict($sourceFile, $targetFile = '')
    {
        $targetFile = ($targetFile == '' ? $this->mainDicFile : $targetFile);
        $allK = [];
        $fp = fopen($sourceFile, 'r');
        while ($line = fgets($fp, 512)) {
            if ($line[0] == '@') {
                continue;
            }
            list($w, $r, $a) = explode(',', $line);
            $a = trim($a);
            $w = iconv('utf-8', UCS2, $w);
            $k = $this->_getIndex($w);
            $allK[$k][$w] = [$r, $a];
        }
        fclose($fp);
        $fp = fopen($targetFile, 'w');
        $headerArr = [];
        $allDat = '';
        $startPos = $this->maskValue * 8;
        foreach ($allK as $k => $v) {
            $dat = serialize($v);
            $dLen = strlen($dat);
            $allDat .= $dat;

            $headerArr[$k][0] = $startPos;
            $headerArr[$k][1] = $dLen;
            $headerArr[$k][2] = count($v);

            $startPos += $dLen;
        }
        unset($allK);
        for ($i = 0; $i < $this->maskValue; $i++) {
            if (!isset($headerArr[$i])) {
                $headerArr[$i] = array(0, 0, 0);
            }
            fwrite($fp, pack("Inn", $headerArr[$i][0], $headerArr[$i][1], $headerArr[$i][2]));
        }
        fwrite($fp, $allDat);
        fclose($fp);
    }

    /**
     * 导出词典的词条
     * @parem $targetFile 保存位置
     * @return void
     */
    public function exportDict($targetFile)
    {
        if (!$this->mainDicHand) {
            $this->mainDicHand = fopen($this->mainDicFile, 'r');
        }
        $fp = fopen($targetFile, 'w');
        for ($i = 0; $i <= $this->maskValue; $i++) {
            $move_pos = $i * 8;
            fseek($this->mainDicHand, $move_pos, SEEK_SET);
            $dat = fread($this->mainDicHand, 8);
            $arr = unpack('I1s/n1l/n1c', $dat);
            if ($arr['l'] == 0) {
                continue;
            }
            fseek($this->mainDicHand, $arr['s'], SEEK_SET);
            $data = @unserialize(fread($this->mainDicHand, $arr['l']));
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $k => $v) {
                $w = iconv(UCS2, 'utf-8', $k);
                fwrite($fp, "{$w},{$v[0]},{$v[1]}\n");
            }
        }
        fclose($fp);
    }
}