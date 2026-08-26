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

use App\support\payment\FakePaymentAdapter;
use App\support\payment\PaymentAdapter;
use App\support\storage\ImageStorage;
use App\support\storage\LocalImageStorage;

return [
    ImageStorage::class => new LocalImageStorage(),
    // Phase 6 (US3): bind the payment-adapter interface to the fake
    // implementation. When a real WeChat Native adapter lands in a
    // later phase, only this binding changes — OrderService and the
    // controllers stay the same.
    PaymentAdapter::class => new FakePaymentAdapter(),
];
