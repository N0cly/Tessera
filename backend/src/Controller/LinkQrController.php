<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\LinkRepository;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Owner-scoped QR generation. Encodes {APP_BASE_URL}/r/{slug} — the permanent
 * redirect URL, never the destination — so the printed QR stays valid forever.
 */
final class LinkQrController
{
    private const QR_SIZE = 512;
    private const QR_MARGIN = 16;

    public function __construct(
        private readonly LinkRepository $links,
        private readonly Security $security,
        #[Autowire('%env(APP_BASE_URL)%')]
        private readonly string $appBaseUrl,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        '/api/links/{id}/qr',
        name: 'link_qr',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function __invoke(string $id, Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            // The /api firewall already enforces this, but be defensive.
            throw new NotFoundHttpException();
        }

        $link = $this->links->find($id);
        if (null === $link || $link->getOwner() !== $user) {
            // Same response shape for "not found" and "not yours" — don't leak existence.
            throw new NotFoundHttpException();
        }

        $format = strtolower((string) $request->query->get('format', 'png'));
        if (!\in_array($format, ['png', 'svg'], true)) {
            throw new BadRequestHttpException($this->translator->trans('link.invalid_qr_format'));
        }

        $encodedUrl = rtrim($this->appBaseUrl, '/').'/r/'.$link->getSlug();

        $qr = new QrCode(
            data: $encodedUrl,
            errorCorrectionLevel: ErrorCorrectionLevel::Quartile,
            size: self::QR_SIZE,
            margin: self::QR_MARGIN,
        );

        $writer = 'svg' === $format ? new SvgWriter() : new PngWriter();
        $result = $writer->write($qr);

        return new Response(
            $result->getString(),
            Response::HTTP_OK,
            [
                'Content-Type' => $result->getMimeType(),
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
