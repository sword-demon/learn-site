<?php

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opens.org/licenses/mit-license.php MIT License
 */

use App\service\SharePosterService;
use App\service\BannerService;
use App\service\CheckinService;
use App\service\PaymentConfigService;
use App\controller\admin\BannerImageController;
use App\controller\media\CourseCoverMediaController;
use App\support\payment\FakePaymentAdapter;
use App\support\payment\PaymentAdapter;
use App\support\payment\ZPayPaymentAdapter;
use App\support\storage\ImageStorage;
use App\support\storage\LocalImageStorage;
use App\support\storage\AssetStorage;
use App\support\storage\LocalAssetStorage;

return [
    ImageStorage::class => new LocalImageStorage(),
    BannerImageController::class => static fn(): BannerImageController => new BannerImageController(
        new LocalImageStorage(prefix: 'banners'),
    ),
    CourseCoverMediaController::class => static fn(): CourseCoverMediaController => new CourseCoverMediaController(
        new LocalImageStorage(),
        new LocalImageStorage(prefix: 'banners'),
    ),
    AssetStorage::class => new LocalAssetStorage(),
    // Production defaults to Z-Pay. Tests and explicit fake mode keep the
    // deterministic adapter without requiring merchant configuration.
    PaymentAdapter::class => function () {
        $driver = getenv('PAYMENT_DRIVER');
        if (getenv('APP_ENV') === 'testing' || $driver === 'fake') {
            return new FakePaymentAdapter();
        }
        return new ZPayPaymentAdapter(new PaymentConfigService());
    },
    SharePosterService::class => new SharePosterService(),
    CheckinService::class => new CheckinService(),
    BannerService::class => new BannerService(),
];
