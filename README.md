# Image on Demand Service TYPO3 Extension

![Extension Logo](https://www.wineworlds.de/logo.png)

**Image on Demand Service** is a TYPO3 extension that provides on-demand image processing services for your TYPO3 website. It allows you to dynamically manipulate and generate images based on specific requests.

## Features

- On-the-fly image processing.
- Dynamic resizing and formatting of images.

## Installation

The extension can be easily installed using [Composer](https://getcomposer.org/) and is available on [Packagist](https://packagist.org/packages/wineworlds/image-on-demand-service).

1. Install the extension using Composer:
   ```bash
   composer require wineworlds/image-on-demand-service
   ```
2. Activate the extension in the TYPO3 backend.

## Usage

The Image on Demand Service extension automatically processes requested images that match a specific URL pattern. It uses the `ImageOnDemandMiddleware` class to handle image requests. When an image request URL matches the specified pattern, the middleware processes the request and serves the manipulated image.

### URL Pattern

The URL pattern for image requests is: `/image-service/{fileId}/{width}/{height}/{format}/{filename}`

- `fileId`: The unique ID of the file to process.
- `width`: The desired width of the processed image.
- `height`: The desired height of the processed image.
- `format`: The desired format (file extension) of the processed image.
- `filename`: The name of the image file (for SEO-friendly URLs).

For example: `/image-service/123/300/200/jpeg/my-seo-friendly-image` will request an image with the ID 123, resized to a width of 300 pixels and a height of 200 pixels, saved in JPEG format, and with the filename "my-seo-friendly-image".

If the requested image is not found or an error occurs during processing, a placeholder "Image Not Found" image will be returned.

## Contributors

- [Kubilay Melnikov](https://www.wineworlds.de/team#kubilay_melnikov) - Developer
- [Arnd Messer](https://www.wineworlds.de/team#arnd_messer) - Developer
- [Miro Olma](https://www.wineworlds.de/team#miro_olma) - Developer

## License

This TYPO3 extension is licensed under the [MIT License](LICENSE).
