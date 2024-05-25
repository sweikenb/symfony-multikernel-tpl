<?php

namespace Admin;

use Shared\MultiKernel;

class AdminKernel extends MultiKernel
{
    protected function getKernelName(): string
    {
        return 'admin';
    }

    protected function getKernelDir(): string
    {
        return __DIR__;
    }
}
