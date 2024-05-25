<?php

namespace Website;

use Shared\MultiKernel;

class WebsiteKernel extends MultiKernel
{
    protected function getKernelName(): string
    {
        return 'website';
    }

    protected function getKernelDir(): string
    {
        return __DIR__;
    }
}
