<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Model;

enum BotProvider: string
{
    case Akamai = 'Akamai';
    case Cloudflare = 'Cloudflare';
    case Sucuri = 'Sucuri';
    case Incapsula = 'Incapsula';
    case DataDome = 'DataDome';
}
