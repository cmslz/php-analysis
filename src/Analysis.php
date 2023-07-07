<?php
/**
 * Each engineer has a duty to keep the code elegant
 * Created by xiaobai at 2023/7/7 17:06
 */

namespace Cmslz\PhpAnalysis;


class Analysis
{
    /**
     * 实现分词功能
     */
    public function run($content, $num)
    {
        PhpAnalysis::$loadInit = false;
        $pa = new PhpAnalysis('utf-8', 'utf-8', false);
        $pa->loadDict();
        $pa->setSource($content);
        $pa->startAnalysis(false);
        return $pa->getFinallyKeywords($num - 1);
    }
}