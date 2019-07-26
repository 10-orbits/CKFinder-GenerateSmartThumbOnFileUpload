# CKFinder-GenerateSmartThumbOnFileUpload
GenerateSmartThumbOnFileUpload: Creates thumbnails on file uploads, the image is cropped smartly based on image histogram, the focus of image remains in center even after crop

CKFinder 3 PHP connector plugin to Create Thumbnails with smart cropping
 * author: 10Orbits - www.10orbits.com (GPL Licensed)
 *
 * Automatically generates thumbnails on file upload, and smartly crops based on image histogram
 * Smart cropping is based on original work by Greg Schoppe (GPL Licensed)
 * http://gschoppe.com
 *
 *
 * 1. Save this file in plugin directory (http://docs.cksource.com/ckfinder3-php/plugins.html).
 * The directory structure should look something like:
 *
 *    plugins
 *    └── ThumbSmartResize
 *        └── ThumbSmartResize.php
 *
 * 2. Add the plugin in config.php.
 *
 *    $config['plugins'] = array('ThumbSmartResize');
 *    $config['ThumbSmartResize'] = array('watermark'=>'https://www.example.com/watermark.png');
 *
 */
