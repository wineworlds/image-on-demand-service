<?php

declare(strict_types=1);

namespace Wineworlds\ImageOnDemandService\Middleware;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
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

class ImageOnDemandMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // Get the requested path
        /** @var NormalizedParams $normalizedParams */
        $normalizedParams = $request->getAttribute('normalizedParams');
        $requestedPath = $normalizedParams->getRequestUri();

        // Define the base path for the image service
        $basePath = '/image-service/';

        // Check if the requested path starts with the base path
        if (strpos($requestedPath, $basePath) === 0) {
            // Remove the base path from the requested path
            $pathWithoutBase = substr($requestedPath, strlen($basePath));

            // Split the path segments
            $pathSegments = explode('/', $pathWithoutBase);

            // Extract the parameters from the path segments
            $fileReferenceId = $pathSegments[0];
            $width = $pathSegments[1];
            $height = $pathSegments[2];
            $format = $pathSegments[3];

            $imageService = GeneralUtility::makeInstance(ImageService::class);
            $fileRepository = GeneralUtility::makeInstance(FileRepository::class);

            try {
                $fileReference = $fileRepository->findFileReferenceByUid((int) $fileReferenceId);

                $fileReference = $imageService->applyProcessingInstructions($fileReference, [
                    "width" => $width,
                    "height" => $height,
                    "fileExtension" => $format
                ]);

                $imageUri = $imageService->getImageUri($fileReference, false);
            } catch (Exception $e) {
                $imageUri = $this->getImageNotFoundImage((int) $width, (int) $height);
                $fileReference = $imageService->getImage($imageUri, null, false);
            }

            $streamFactory = new StreamFactory();
            $response = (new Response())
                ->withAddedHeader('Content-Length', (string)$fileReference->getSize())
                ->withAddedHeader('Content-Type', $fileReference->getMimeType())
                ->withBody($streamFactory->createStreamFromFile($imageUri));

            return $response;
        }


        return $handler->handle($request);
    }

    public function getImageNotFoundImage($width = 400, $height = 400, $text = "Image not found!"): string
    {
        $imageProcessor = $this->initializeImageProcessor();
        $gifOrPng = $imageProcessor->gifExtension;
        $image = imagecreatetruecolor($width, $height);
        $backgroundColor = imagecolorallocate($image, 128, 128, 150);
        imagefilledrectangle($image, 0, 0, $width, $height, $backgroundColor);
        $workArea = [0, 0, $width, $height];
        $conf = [
            'iterations' => 1,
            'angle' => 0,
            'antiAlias' => 1,
            'text' => strtoupper($text),
            'align' => 'center',
            'fontColor' => '#003366',
            'fontSize' => 30,
            'fontFile' => ExtensionManagementUtility::extPath('install') . 'Resources/Private/Font/vera.ttf',
            'offset' => '0,' . $height / 2 + 10,
        ];
        $conf['BBOX'] = $imageProcessor->calcBBox($conf);
        $imageProcessor->makeText($image, $conf, $workArea);
        $outputFile = $this->getImagesPath() . $imageProcessor->filenamePrefix . StringUtility::getUniqueId('gdText') . '.' . $gifOrPng;
        $imageProcessor->ImageWrite($image, $outputFile);
        $imResult = $imageProcessor->getImageDimensions($outputFile);

        return $imResult[3];
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
        $imageProcessor->filenamePrefix = 'imageOnDemandService-';
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
}
