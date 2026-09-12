<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests;

use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\Live\ScreenPreview;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Milpa\Live\Security\{SignedXhtmlStateTransferCodec,HmacStateSigner,FileNonceStore};
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use PHPUnit\Framework\TestCase;

final class ScreenDraftReviewLinkTest extends TestCase
{
    public function testOnlyABootedReviewServiceOffersTheHostsReviewRoute(): void
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['live' => ['route' => '/ui']]));
        $data = new DesktopData($container);
        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner(str_repeat('s', 32)), new FileNonceStore(sys_get_temp_dir() . '/milpa-review-link-nonces.json'));
        $preview = new ScreenPreview($codec, $data, catalog:new Catalog('es'));
        self::assertStringNotContainsString('data-screen-src="/ui/review"', $preview->render());
        $container->registerService('Milpa\\AppRuntime\\Web\\ScreenDrafts', new \stdClass());
        $html = $preview->render();
        self::assertStringContainsString('data-screen-src="/ui/review"', $html);
        self::assertStringContainsString('Revisar borradores de pantalla', $html);
        self::assertStringContainsString('data-screen-name=""', $html);
    }
}
