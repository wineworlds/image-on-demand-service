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
    private int $fontSize = 16;
    private int $width = 400;
    private int $height = 400;
    private string $text;
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
             * Auch die darauf folgenden Methoden erzeugen diesen Fehler.
             * 
             * Allerdings fällt mir gerade ein,
             * das auch wenn kein Bild gefunden wird ein throw ausgelöst wird,
             * hier würde ich allerdings gerne ein generiertes Bild als Antwort liefern.
             */
            $this->validateMiddleware();


        } catch (Exception $e) {
            $handler->handle($request);
        }

        /**
         * TODO: Neuer Aufbau mit eigenen Methoden
         * 
         * 1. Prüfen ob der image service angesprochen wird.
         * 
         * 2. Path Params und Query Params Extrahieren & Validieren.
         * 
         * 3. Falls keine ID Vorhanden ist Dummy Image erzeugen.
         * 
         * 4. Falls ID Vorhanden Bild laden & processen.
         * 
         * 5. Falls es zu einem Fehler kommt, Bild nicht Gefunden Bild erzeugen.
         */

        // This variable is needed for applyProcessingInstructions.
        $parameters = [];

        // With this setting, you can ensure that a new image is not generated for each pixel.
        $imageStepWidth = $this->getConfigurationValue('imageStepWidth');
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
        $width = ceil((int)($pathSegments[0] ?? 300) / $imageStepWidth) * $imageStepWidth;
        $height = ceil((int)($pathSegments[1] ?? 300) / $imageStepHeight) * $imageStepHeight;

        $this->width = $width;
        $this->height = $height;

        /**
         * TODO: Do we want to switch to an alternative syntax here?
         * 
         * Perhaps something like /400x200/ instead of /400/200/.
         * Then, we could omit the height property if we want to use the original attributes of the image. 
         * 
         * This would look like "/400x/" or "/x200/" depending on whether you want to specify the height or width.
         */

        // Here, you can use 'c' or 'm'.
        $parameters['width'] = $width . 'c';
        $parameters['height'] = $height . 'c';

        // Extract & validate parameter
        $queryString = $normalizedParams->getQueryString();
        $queryParams = Query::parse($queryString);

        // Define cache identifier
        $cacheIdentifier = 'image_cache_' . (string)$width . '_' . (string)$height . '_' . md5($queryString);

        // Return cached, when exists
        $imageUri = $this->cache->get($cacheIdentifier);
        if ($imageUri !== false) {
            return $this->createResponse(
                $imageUri,
                (string)filesize($imageUri),
                mime_content_type($imageUri)
            );
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
            $cropVariantCollection = CropVariantCollection::create((string)$fileReference->getProperty('crop'));
            $cropArea = $cropVariantCollection->getCropArea($cropVariant);
            $parameters['crop'] = $cropArea->isEmpty() ? null : $cropArea->makeAbsoluteBasedOnFile($fileReference);

            $fileReference = $this->imageService->applyProcessingInstructions($fileReference, $parameters);
            $imageUri = $this->imageService->getImageUri($fileReference, false);
        } catch (Exception $e) {
            // ?text=das ist nur ein test!!
            $text = (string)($queryParams['text'] ?? null);
            $this->text = $text;

            // ?bgColor=ff0000
            $bgColor = (string)($queryParams['bgColor'] ?? '000000');
            $this->backgroundColor = $bgColor;

            // ?textColor=00ff00
            $textColor = (string)($queryParams['textColor'] ?? 'ffffff');
            $this->fontColor = $textColor;

            $imageUri = $this->createImageNotFoundImage($text);
            $fileReference = $this->imageService->getImage($imageUri, null, false);
        }

        $this->cache->set($cacheIdentifier, $imageUri);

        return $this->createResponse(
            $imageUri,
            (string) $fileReference->getSize(),
            $fileReference->getMimeType()
        );
    }

    private function extractParameter()
    {

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
    private function createDummyImage(string $text = "Dummy Image"): string
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
        $imageProcessor->makeText($image, $conf, $workArea);

        // Write file and return the path 
        $filePath = $this->getImagesPath() . $imageProcessor->filenamePrefix . '_' . StringUtility::getUniqueId() . '.' . $gifOrPng;
        $imageProcessor->ImageWrite($image, $filePath);

        return $filePath;
    }

    /**
     * Create image not found image and return the url to the image.
     */
    public function createImageNotFoundImage(string $text = "Image not found!"): string
    {
        return $this->createDummyImage($text);
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
