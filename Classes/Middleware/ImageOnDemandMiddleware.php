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
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Extbase\Service\ImageService;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\FileInterface;

final class ImageOnDemandMiddleware implements MiddlewareInterface
{
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
        // Dieser Variable wird für applyProcessingInstructions benötigt.
        $parameters = [];

        // Mit dieser einstellung kann man Gewährleisten das nicht für jeden Pixel ein neues Bild generiert wird.
        $imageStepWith = $this->getConfigurationValue('imageStepWith');
        $imageStepHeight = $this->getConfigurationValue('imageStepHeight');

        // Get the requested path
        /** @var NormalizedParams $normalizedParams */
        $normalizedParams = $request->getAttribute('normalizedParams');
        $requestUri = $normalizedParams->getRequestUri();

        // Define the base path for the image service
        $basePath = '/image-service/';

        // Check if the requested path starts with the base path
        if (strpos($requestUri, $basePath) !== 0) return $handler->handle($request);

        // Remove the base path from the requested path
        $pathWithoutBase = substr($requestUri, strlen($basePath));

        // Split the path segments
        $pathSegments = explode('/', $pathWithoutBase);

        // Extract the parameters from the path segments
        $width = ceil((int)($pathSegments[0] ?? 300) / $imageStepWith) * $imageStepWith;
        $height = ceil((int)($pathSegments[1] ?? 300) / $imageStepHeight) * $imageStepHeight;

        /**
         * TODO: Wollen wir hier auf eine alternative schreibe wechseln?
         * 
         * Eventuell so was wie /400x200/ anstatt /400/200/.
         * Dann könnte man die eigenschaft der Höhe weglassen,
         * falls man das Bild mit den Originalen Attributen möchte. 
         * 
         * Das würde dann so aussehen "/400x/ oder /x200/" je nach dem ob man die Höhe oder breite angeben will.
         */

        // Hier kann man c oder m verwenden.
        $parameters['width'] = $width . 'c';
        $parameters['height'] = $height . 'c';

        // Extract & validate parameter
        $queryString = $normalizedParams->getQueryString();
        $queryParams = Query::parse($queryString);

        // Return Cached, when exists
        $cacheIdentifier = 'image_cache_' . (string)$width . '_' . (string)$height . '_' . md5($queryString);
        $imageUri = $this->cache->get($cacheIdentifier);
        if ($imageUri !== false) {
            $streamFactory = new StreamFactory();
            $response = (new Response())
                ->withAddedHeader('Content-Length', (string)filesize($imageUri))
                ->withAddedHeader('Content-Type', mime_content_type($imageUri))
                ->withBody($streamFactory->createStreamFromFile($imageUri));

            return $response;
        }

        // ?fileExt=webp
        $fileExt = (string)($queryParams['fileExt'] ?? '');
        if (GeneralUtility::inList(strtolower($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] ?? ''), $fileExt)) {
            $parameters['fileExtension'] = $fileExt;
        }

        try {
            $fileReferenceId = (int)($queryParams['id'] ?? 0);
            $fileReference = $this->fileRepository->findFileReferenceByUid($fileReferenceId);

            $cropVariant = (string)($queryParams['crop'] ?? 'default');
            $cropVariantCollection = $this->createCropVariant((string)$fileReference->getProperty('crop'));
            $cropArea = $cropVariantCollection->getCropArea($cropVariant);
            $parameters['crop'] = $cropArea->isEmpty() ? null : $cropArea->makeAbsoluteBasedOnFile($fileReference);

            $fileReference = $this->imageService->applyProcessingInstructions($fileReference, $parameters);
            $imageUri = $this->imageService->getImageUri($fileReference, false);
        } catch (Exception $e) {
            // ?text=das ist nur ein test!!
            $text = (string)($queryParams['text'] ?? 'Dummy Image');

            // ?bgColor=ff0000
            $bgColor = (string)($queryParams['bgColor'] ?? '000000');

            // ?textColor=00ff00
            $textColor = (string)($queryParams['textColor'] ?? 'ffffff');

            $imageUri = $this->getImageNotFoundImage((int) $width, (int) $height, $text, $bgColor, $textColor);
            $fileReference = $this->imageService->getImage($imageUri, null, false);
        }

        $this->cache->set($cacheIdentifier, $imageUri);

        return $this->createResponse($fileReference, $imageUri);
    }

    private function createResponse(FileInterface $fileReference, $imageUri): Response
    {

        $streamFactory = new StreamFactory();
        $response = (new Response())
            ->withAddedHeader('Content-Length', (string)$fileReference->getSize())
            ->withAddedHeader('Content-Type', $fileReference->getMimeType())
            ->withBody($streamFactory->createStreamFromFile($imageUri));

        return $response;
    }

    public function getImageNotFoundImage($width = 400, $height = 400, $text = "Image not found!", $bgColor = "000000", $textColor = "ffffff"): string
    {
        $height = $height <= 300 ? 300 : $height;
        $width = $width <= 300 ? 300 : $width;
        $fontSize = max([min([$width / 15, 80]), 20]);

        $imageProcessor = $this->initializeImageProcessor();
        $gifOrPng = $imageProcessor->gifExtension;
        $image = imagecreatetruecolor($width, $height);

        $bgColor = $imageProcessor->convertColor('#' . $bgColor);
        $backgroundColor = imagecolorallocate($image, $bgColor[0], $bgColor[1], $bgColor[2]);

        imagefilledrectangle($image, 0, 0, $width, $height, $backgroundColor);
        $workArea = [0, 0, $width, $height];
        $conf = [
            'iterations' => 1,
            'angle' => 0,
            'antiAlias' => 1,
            'text' => strtoupper($text),
            'align' => 'center',
            'fontColor' => '#' . $textColor,
            'fontSize' => $fontSize,
            'fontFile' => ExtensionManagementUtility::extPath('install') . 'Resources/Private/Font/vera.ttf',
            'offset' => '0,' . $height / 2 + $fontSize / 3,
        ];
        $conf['BBOX'] = $imageProcessor->calcBBox($conf);
        $imageProcessor->makeText($image, $conf, $workArea);
        $outputFile = $this->getImagesPath() . $imageProcessor->filenamePrefix . StringUtility::getUniqueId('imageNotFound') . '.' . $gifOrPng;
        $imageProcessor->ImageWrite($image, $outputFile);

        return $outputFile;
    }

    /**
     * Initialize image processor
     *
     * @return GraphicalFunctions Initialized image processor
     */
    protected function initializeImageProcessor(): GraphicalFunctions
    {
        $imageProcessor = GeneralUtility::makeInstance(GraphicalFunctions::class);
        $imageProcessor->dontCheckForExistingTempFile = true;
        $imageProcessor->filenamePrefix = 'image_on_demand_service-';
        $imageProcessor->dontCompress = true;

        return $imageProcessor;
    }

    /**
     * Return the temp image dir.
     * If not exist it will be created
     */
    protected function getImagesPath(): string
    {
        $imagePath = Environment::getPublicPath() . '/typo3temp/assets/images/';
        if (!is_dir($imagePath)) {
            GeneralUtility::mkdir_deep($imagePath);
        }
        return $imagePath;
    }

    /**
     * Return a single configuration value
     */
    protected function getConfigurationValue(string $path)
    {
        return $this->extensionConfiguration
            ->get('image_on_demand_service', $path);
    }

    protected function createCropVariant(string $cropString): CropVariantCollection
    {
        return CropVariantCollection::create($cropString);
    }
}
