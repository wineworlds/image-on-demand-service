<?php

declare(strict_types=1);

namespace Wineworlds\ImageOnDemandService\Middleware;

use Exception;
use GuzzleHttp\Psr7\Query;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;

final class ImageOnDemandMiddleware implements MiddlewareInterface
{
    private array $parameters = [];
    private int $fontSize = 16;
    private string $cropVariant = 'default';
    private string $cacheIdentifier = '';
    private int $fileReferenceId = 0;
    private int $width = 400;
    private int $height = 400;
    private string $text = "Dummy Image";
    private string $backgroundColor = "000000";
    private string $fontColor = "ffffff";
    private ServerRequestInterface  $request;
    private RequestHandlerInterface $handler;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly FileRepository $fileRepository,
        private readonly ImageService $imageService,
        private readonly FrontendInterface $cache,
    ) {
    }

    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $this->request = $request;
        $this->handler = $handler;

        try {
            /**
             * Hier wird geprüft ob diese Middleware angesprochen wird.
             * Ohne eine weitere Extension ist es nicht möglich die Middleware an einen bestimmten Pfad zu binden.
             * 
             * Wenn die URL nicht zutrifft wird ein "throw" angestoßen und über den catch geht es dann weiter an die nächste middleware.
             * 
             * Wenn das Bild nicht gefunden wird, wird auch ein "throw" ausgelöst und ein "Bild nicht gefunden" Bild erzeugt.
             */
            $this->validateMiddleware();

            $this->extractParameter();

            $imageUri = $this->cache->get($this->cacheIdentifier);
            if ($imageUri !== false) {
                return $this->createResponse(
                    $imageUri,
                    (string)filesize($imageUri),
                    mime_content_type($imageUri)
                );
            }

            if (!$this->fileReferenceId) {
                $imageUri = $this->createDummyImage($this->text);
                $fileReference = $this->imageService->getImage($imageUri, null, false);
            } else {
                [$fileReference, $imageUri] = $this->loadImage();
            }

            $this->cache->set($this->cacheIdentifier, $imageUri);

            return $this->createResponse(
                $imageUri,
                (string) $fileReference->getSize(),
                $fileReference->getMimeType()
            );
        } catch (Exception $e) {
            switch ($e->getMessage()) {
                case 'IMAGE_NOT_FOUND':
                    $imageNotFoundUri = $this->createDummyImage("Image not found!");
                    $fileReference = $this->imageService->getImage($imageNotFoundUri, null, false);

                    return $this->createResponse(
                        $imageNotFoundUri,
                        (string) $fileReference->getSize(),
                        $fileReference->getMimeType()
                    );
                    break;

                default:
                    return $this->handler->handle($request);
                    break;
            }
        }
    }

    private function extractParameter()
    {
        // With this setting, you can ensure that a new image is not generated for each pixel.
        $imageStepWidth = $this->getConfigurationValue('imageStepWidth');
        $imageStepHeight = $this->getConfigurationValue('imageStepHeight');

        // Get the requested path
        /** @var NormalizedParams $normalizedParams */
        $normalizedParams = $this->request->getAttribute('normalizedParams');
        $requestUri = $normalizedParams->getRequestUri();

        // Define the base path for the image service
        $basePath = '/image-service/';

        // Remove the base path from the requested path
        [$path] = explode('?', substr($requestUri, strlen($basePath)));

        // Split the path segments
        [$width, $height] = explode('/', $path);

        // Extract the parameters from the path segments
        $width = (int) ceil(($width ?? 400) / $imageStepWidth) * $imageStepWidth;
        $height = (int) ceil(($height ?? 400) / $imageStepHeight) * $imageStepHeight;

        $this->width = $width;
        $this->width = $width;

        // Here, you can use 'c' or 'm'.
        $this->parameters['width'] = $width . 'c';
        $this->parameters['height'] = $height . 'c';

        // Extract & validate parameter
        $queryString = $normalizedParams->getQueryString();
        $queryParams = Query::parse($queryString);

        // set cache key
        $this->cacheIdentifier = 'image_cache_' . (string)$width . '_' . (string)$height . '_' . md5($queryString);

        // ?fileExt=webp
        $fileExt = (string)($queryParams['fileExt'] ?? '');
        if (GeneralUtility::inList(strtolower($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] ?? ''), $fileExt)) {
            $this->parameters['fileExtension'] = $fileExt;
        }

        // ?text=das ist nur ein test!!
        $text = (string)($queryParams['text'] ?? null);
        if ($text) $this->text = $text;

        // ?bgColor=ff0000
        $bgColor = (string)($queryParams['bgColor'] ?? '000000');
        if ($bgColor) $this->backgroundColor = $bgColor;

        // ?textColor=00ff00
        $textColor = (string)($queryParams['textColor'] ?? 'ffffff');
        if ($textColor) $this->fontColor = $textColor;

        // ?id=88
        $fileReferenceId = (int)($queryParams['id'] ?? 0);
        if ($fileReferenceId) $this->fileReferenceId = $fileReferenceId;

        // ?crop=desktop
        $cropVariant = (string)($queryParams['crop'] ?? 'default');
        if ($cropVariant) $this->cropVariant = $cropVariant;
    }

    private function loadImage()
    {
        try {
            $fileReference = $this->fileRepository->findFileReferenceByUid($this->fileReferenceId);

            $cropVariantCollection = CropVariantCollection::create((string)$fileReference->getProperty('crop'));
            $cropArea = $cropVariantCollection->getCropArea($this->cropVariant);
            $this->parameters['crop'] = $cropArea->isEmpty() ? null : $cropArea->makeAbsoluteBasedOnFile($fileReference);

            $fileReference = $this->imageService->applyProcessingInstructions($fileReference, $this->parameters);
            $imageUri = $this->imageService->getImageUri($fileReference, false);

            return [$fileReference, $imageUri];
        } catch (Exception $e) {
            throw new Exception("IMAGE_NOT_FOUND");
        }
    }

    private function validateMiddleware()
    {
        // Get the requested path
        /** @var NormalizedParams $normalizedParams */
        $normalizedParams = $this->request->getAttribute('normalizedParams');
        $requestUri = $normalizedParams->getRequestUri();

        // Define the base path for the image service
        $basePath = '/image-service/';

        // Check if the requested path starts with the base path
        if (strpos($requestUri, $basePath) !== 0) throw new Exception();
    }

    private function createResponse(string $imageUri, string $fileSize, string $fileMimeType): Response
    {

        $streamFactory = new StreamFactory();
        $response = (new Response())
            ->withAddedHeader('Content-Length', $fileSize)
            ->withAddedHeader('Content-Type', $fileMimeType)
            ->withBody($streamFactory->createStreamFromFile($imageUri));

        return $response;
    }

    /**
     * Create dummy image and return the url to the image.
     */
    private function createDummyImage(string $text): string
    {
        $w = $this->width;
        $h = $this->height;
        $s = $this->fontSize;

        // Create blank image
        $imageProcessor = $this->initializeImageProcessor();
        $gifOrPng = $imageProcessor->gifExtension;
        $dummyImage = imagecreatetruecolor($w, $h);

        // Fill Background with color
        $bgColorHEX = "#$this->backgroundColor";
        [$R, $G, $B] = $imageProcessor->convertColor($bgColorHEX);
        $bgColorRGB = imagecolorallocate($dummyImage, $R, $G, $B);
        imagefilledrectangle($dummyImage, 0, 0, $w, $h, $bgColorRGB);

        // Write text inside image
        $fontColorHEX = "#$this->fontColor";
        $offsetY = $h / 2 + $s / 3;
        $workArea = [0, 0, $w, $h];
        $conf = [
            'iterations' => 1,
            'angle' => 0,
            'antiAlias' => 1,
            'text' => $text,
            'align' => 'center',
            'fontColor' => $fontColorHEX,
            'fontSize' => $s,
            // TODO: Diese Abhängigkeit muss aufgelöst werden.
            'fontFile' => ExtensionManagementUtility::extPath('install') . 'Resources/Private/Font/vera.ttf',
            'offset' => "0,$offsetY",
        ];
        $conf['BBOX'] = $imageProcessor->calcBBox($conf);
        $imageProcessor->makeText($dummyImage, $conf, $workArea);

        // Write file and return the path 
        $filePath = $this->getImagesPath() . $imageProcessor->filenamePrefix . '_' . StringUtility::getUniqueId() . '.' . $gifOrPng;
        $imageProcessor->ImageWrite($dummyImage, $filePath);

        return $filePath;
    }

    /**
     * Initialize image processor
     */
    protected function initializeImageProcessor(): GraphicalFunctions
    {
        $imageProcessor = GeneralUtility::makeInstance(GraphicalFunctions::class);
        $imageProcessor->filenamePrefix = 'image_on_demand_service';

        return $imageProcessor;
    }

    /**
     * Return the temp image dir.
     * If not exist it will be created
     */
    protected function getImagesPath(): string
    {
        $imagePath = Environment::getPublicPath() . '/typo3temp/assets/images/';

        if (!is_dir($imagePath)) GeneralUtility::mkdir_deep($imagePath);

        return $imagePath;
    }

    /**
     * Return a single configuration value
     */
    protected function getConfigurationValue(string $path = ''): mixed
    {
        return $this->extensionConfiguration->get('image_on_demand_service', $path);
    }
}
