<?php

/**
 * CKFinder 3 PHP connector plugin to Create Thumbnails with smart cropping
 * author: 10Orbits - www.10orbits.com (GPL Licensed)
 *
 * automatically generates thumbnails on file upload, and is smartly cropped based on image histogram
 * smart cropping is based on work by Greg Schoppe (GPL Licensed)
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

namespace CKSource\CKFinder\Plugin\ThumbSmartResize;

use CKSource\CKFinder\CKFinder;
use CKSource\CKFinder\Event\AfterCommandEvent;
use CKSource\CKFinder\Event\CKFinderEvent;
use CKSource\CKFinder\Filesystem\File\File;
use CKSource\CKFinder\Image;
use CKSource\CKFinder\Plugin\PluginInterface;
use League\Flysystem\Cached\CachedAdapter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ThumbSmartResize implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var CKFinder
     */
    protected $app;

    protected $path, $img, $img_size,$orig_w, $orig_h, $x, $y, $x_weight, $y_weight;

    public function setContainer(CKFinder $app)
    {
        $this->app = $app;
    }

    public function getDefaultConfig()
    {
        return [];
    }

    public function resize(AfterCommandEvent $event)
    {
      $config = $this->app['config'];
      $responseData = $event->getResponse()->getData();
      $this->setImage($event);
      $sizes=$config->get('images')['sizes'];
      foreach ($sizes as $name=>$size) {
        $w=$size['width'];
        $h=$size['height'];
        //gets_smart_cropped_image

        $img2    = $this->get_resized($w,$h);
        if (!file_exists($this->path."__smart_thumbs/")) {
            mkdir($this->path."__smart_thumbs/", 0755, true);
        }
        $img2=$this->addWatermark($img2);

        //imageinterlace($img2, true);

        $fn=explode(".",$responseData['fileName']);
        $fn=$this->path."__smart_thumbs/".$fn[0]."_".$name.".png";
        imagepng($img2,$fn,9);
      }
    }
    public function addWatermark($img){
      $config = $this->app['config'];
      $fileName=$config->get('ThumbSmartResize')['watermark'];
      if(!$fileName){
        return $img;
      }
      $img_type=exif_imagetype($fileName);
      switch($img_type){
        case IMAGETYPE_JPEG:
          $watermark = imagecreatefromjpeg($fileName);
        break;
        case IMAGETYPE_GIF:
          $watermark = imagecreatefromgif($fileName);
        break;
        case IMAGETYPE_PNG:
          $watermark = imagecreatefrompng($fileName);
        break;
      }
      $watermark_height= imagesy($watermark);
      $watermark_width=imagesx($watermark);
      $r = $watermark_width / $watermark_height;
      if($watermark_width>150){
        $w=150;
        $h=$w/$r;
        $targetImage = imagecreatetruecolor( $w, $h );
        imagealphablending( $targetImage, false );
        imagesavealpha( $targetImage, true );

        imagecopyresampled( $targetImage, $watermark,
                    0, 0,
                    0, 0,
                    $w, $h,
                    $watermark_width, $watermark_height );

        /*$dst = imagecreatetruecolor($w, $h);
        imagesavealpha($dst, true);
        imagealphablending( $dst, false );
        $color = imagecolorallocatealpha($dst, 0, 0, 0, 127); //fill transparent back
        imagefill($dst, 0, 0, $color);
        imagecopyresampled($dst, $watermark, 0, 0, 0, 0, $w, $h, $watermark_width, $watermark_height);*/
        //$watermark=$targetImage;
        $watermark_width=$w;
        $watermark_height=$h;
        $watermark=$targetImage;
      }



      $x=imagesx($img)-$watermark_width;
      $y=imagesy($img)-$watermark_height;
      $cut=imagecreatetruecolor( $watermark_width, $watermark_height );
      imagecopy($cut, $img, 0, 0, $x, $y, $watermark_width, $watermark_height);
      imagecopy($cut, $watermark, 0, 0, 0, 0, $watermark_width, $watermark_height);
      imagecopymerge($img, $cut, $x, $y, 0, 0, $watermark_width, $watermark_height, 100);
      //imagecopymerge($img, $watermark, $x, $y, 0, 0, $watermark_width, $watermark_height, 100);
      return $img;
    }
    protected function setImage($event){
      $workingFolder = $this->app['working_folder'];
      $config = $this->app['config'];
      $responseData = $event->getResponse()->getData();
      $this->path=$config->get('backends')['default']['root']."/".$workingFolder->getPath();
      $fileName = $this->path.$responseData['fileName'];

      $img_type=exif_imagetype($fileName);
      switch($img_type){
        case IMAGETYPE_JPEG:
          $img = imagecreatefromjpeg($fileName);
        break;
        case IMAGETYPE_GIF:
          $img = imagecreatefromgif($fileName);
        break;
        case IMAGETYPE_PNG:
          $img = imagecreatefrompng($fileName);
        break;
      }

    	$this->img_size = list($width, $height) = getimagesize($fileName);
      $this->img = $img;
      $this->orig_w = imageSX($img);
      $this->orig_h = imageSY($img);

    }
    public static function getSubscribedEvents()
    {
        return [CKFinderEvent::AFTER_COMMAND_FILE_UPLOAD => 'resize'];
    }

    /****************************************************************
      Original Smart Cropping Class by
      Copyright 2014 Greg Schoppe (GPL Licensed)
      http://gschoppe.com

      Desc: Takes a GD2 image reference and a target width/height,
            and produces a cropped resized image that puts the focus
            of the image at or close to a rule of thirds line.
      NOTE: THIS CLASS IS A PROOF OF CONCEPT AND RUNS SLOWLY.
            BE SURE TO CACHE RESULTS AND, IF POSSIBLE RUN AS A CHRON,
            BACKGROUND OR AJAX SCRIPT.
     ****************************************************************/


    /* find_focus - identifies the focal point of an image,
                    based on color difference and image entropy
       takes: $slices - integer representing precision of focal
                        point. larger values are slower, but more
                        precise (optional, defaults to 20)
              $weight - float between 0 and 1 representing
                        weighting between entropy method (0) and
                        color method (1) (optional, defaults to .5)
              $sample - integer representing the downsampled
                        resolution of the image to test. larger
                        values are slower, but more precise
                        (optional, defaults to 200) */
    public function find_focus($slices = 50, $weight = .5, $sample=200) { //$slices = 200;
        // get a sample image to play with
        $temp = $this->rough_in_size($sample, $sample);
        $w = imageSX($temp);
        $h = imageSY($temp);
        // smooth it a little to help reduce the effects of noise
        imagefilter($temp, IMG_FILTER_SMOOTH, 7);
        // get the mean color of the entire image
        $avgColor = $this->average_color($temp,0,0,$w,$h);
        $left = $top = 0;
        //find the horizontal focus position
        $sliceArray   = array();
        // -get the width of each vertical slice
        $slice = round($w/$slices);
        for($i=0;$i<$slices;$i++) {
            if($weight == 0) {
                // -we're skipping this calculation because
                // -weight 0 doesnt take color into account
                $colorSlice = 0;
            } else {
                // -get the distance from the average color of
                // -the image to the average color of the slice
                $color          = $this->average_color($temp, $i*$slice, 0, $slice, $h);
                $colorSlice     = $this->euclidean_distance($avgColor, $color);
				//print_r($colorSlice);
            }
            if($weight == 1) {
                // -we're skipping this calculation because
                // -weight 1 doesnt take entropy into account
                $entropySlice = 0;
            } else {
                // -get the level of entropy of the slice
                $entropySlice   = $this->get_entropy($temp, $i*$slice, 0, $slice, $h);
            }
			//print_r($entropySlice);

            // -get a weighted average of the two values
            $sliceArray[$i] = $colorSlice*$weight + $entropySlice*(1-$weight);
        }
		//print_r($sliceArray);

        // -get the array index of the best slice
        $focus   = array_search(max($sliceArray), $sliceArray);
        // -get the pixel value corresponding with the center of that slice
        $x       = ($focus + 0.5)*$slice/$w;
        // figure out which way to weight the image from the focus
        $xWeight = $this->get_array_weight($sliceArray, $focus);
        unset($sliceArray);
        //find the vertical focus position
        $sliceArray   = array();
        // -get the width of each horizontal slice
        $slice = round($h/$slices);
		$uniqueColor = array();
        for($i=0;$i<$slices;$i++) {
            if($weight == 0) {
                // -we're skipping this calculation because
                // -weight 0 doesnt take color into account
                $colorSlice = 0;
            } else {
                // -get the distance from the average color of
                // -the image to the average color of the slice
                $color          = $this->average_color($temp, 0, $i*$slice, $w, $slice);
				if(!in_array($color,$uniqueColor)){
					$uniqueColor[] = $color;
				}
                $colorSlice     = $this->euclidean_distance($avgColor, $color);
            }
            if($weight == 1) {
                // -we're skipping this calculation because
                // -weight 1 doesnt take entropy into account
                $entropySlice = 0;
            } else {
                // -get the level of entropy of the slice
                $entropySlice   = $this->get_entropy($temp, 0, $i*$slice, $w, $slice);
            }
            // -get a weighted average of the two values
            $sliceArray[$i] = $colorSlice*$weight + $entropySlice*(1-$weight);
        }

        // -get the array index of the best slice
        $focus   = array_search(max($sliceArray), $sliceArray);
        // -get the pixel value corresponding with the center of that slice
        $y       = ($focus + 0.5)*$slice/$h;
        // figure out which way to weight the image from the focus
        $yWeight = $this->get_array_weight($sliceArray, $focus);

        // set these values as the focus of the image
        $this->set_focus($x, $y, $xWeight, $yWeight);
    }

    /* set_focus - sets the focal point of an image manually
       takes: $x - integer representing the pixel position
                   of the focal point horizontally
              $y - integer representing the pixel position
                   of the focal point vertically
              $xWeight - float from -1 to 1 representing
                         whether the image is more interesting
                         to the left of the focal point or the
                         right (optional, defaults to 0)
              $yWeight - float from -1 to 1 representing
                         whether the image is more interesting
                         above the focal point or below
                         (optional, defaults to 0)
       returns: boolean - true for success, false for failure */
    public function set_focus($x, $y, $xWeight=0, $yWeight=0) { $x = 400/imageSX($this->img); $y = 167/imageSY($this->img); //echo $x.'-'.$y; die;
        $w = imageSX($this->img);
        $h = imageSY($this->img);
        // check to make sure these values are valid
        if($x < 0 || $x >= $w || $y < 0 || $y >= $h)
            return false;
        // set the focus point
        $this->x = $x;
        $this->x_weight = $xWeight;
        $this->y = $y;
        $this->y_weight = $yWeight;
        return true;
    }
    /* get_resized   - creates a cropped resized image with the
                       focal point of the image at or close to
                       one of the rule of thirds lines
       takes: $newW   - integer representing the target width of
                        the image to return
              $newH   - integer representing the target height
                        of the image to return
              $slices - integer representing precision of focal
                        point. larger values are slower, but more
                        precise (optional, defaults to 20)
              $weight - float between 0 and 1 representing
                        weighting between entropy method (0) and
                        color method (1) (optional, defaults to .5)
              $sample - integer representing the downsampled
                        resolution of the image to test. larger
                        values are slower, but more precise
                        (optional, defaults to 200)
       returns: GD2 image resource to resized image on success, false
                on failure */
    public function get_resized($newW, $newH, $slices=200, $weight=.5, $sample=200) {
        if($newW < 1 || $newH < 1) return false;
        // scale the image proportionally to cover the area
        $temp = $this->rough_in_size($newW, $newH);
        $w   = imageSX($temp);
        $h   = imageSY($temp);
        // if we're done, skip the rest
        if($w == $newW && $h == $newH) return $temp;
        // if a focus wasn't defined already, do it now
        if(!$this->x || !$this->y) $this->find_focus($slices, $weight, $sample);
        // this is the x and y coords for the corner of the crop
        $x = $y = 0;
        if($w > $newW) {
            // we're cropping width
            if($this->x_weight == 0) {
                // center the image on the focal point
                $x = $this->x*$w - 0.5*$newW;
            } elseif($this->x_weight > 0) {
                // put the focal point on the right rule of thirds line
                $x = $this->x*$w - 2/3*$newW;
            } else {
                // put the focal point on the left rule of thirds line
                $x = $this->x*$w - 1/3*$newW;
            }
            // correct the position to be inside the image's bounds
            if($x >= $w-$newW) $x = $w-$newW-1;
            if($x < 0) $x = 0;
        } else {
            // we're cropping height
            if($this->y_weight == 0) {
                // center the image on the focal point
                $y = $this->y*$h - 0.5*$newH;
            } elseif($this->y_weight > 0) {
                // put the focal point on the top rule of thirds line
                $y = $this->y*$h - 2/3*$newH;
            } else {
                // put the focal point on the bottom rule of thirds line
                $y = $this->y*$h - 1/3*$newH;
            }
            // correct the position to be inside the image's bounds
            if($y >= $h-$newH) $y = $h-$newH-1;
            if($y < 0) $y = 0;
        }
        // make the final cropped image
        $croppedThumb = imagecreatetruecolor($newW,$newH);
        imagecopyresampled($croppedThumb, $temp, 0, 0, $x, $y, $newW, $newH, $newW, $newH);
		//header('Content-Type: image/jpeg');
		//imagejpeg($temp);
        imagedestroy($temp);
        return($croppedThumb);
    }

    /* rough_in_size  - PROTECTED resizes image proportionally,
                        so that the given width and height are
                        covered. */
    protected function rough_in_size($newW, $newH) {
        $w = $this->orig_w;
        $h = $this->orig_h;
        // image must be valid
        if($w < 1 || $h < 1)
            return false;
        // first proportionally resize dimensions by width dimension
        $tempW = $newW;
        $tempH = ($h*$newW)/$w;
        // if it's too small, try resizing dimensions by height instead
        if($tempH<$newH) {
            $tempW = ($w*$newH)/$h;
            $tempH = $newH;
        }
        // if it's still too small for some reason,
        // just force dimensions to size (in case of rounding errors)
        if($tempW < $newW || $tempH < $newH) {
            $tempW = $newW;
            $tempH = $newH;
        }
        // make the resized image
        $temp = imagecreatetruecolor($tempW, $tempH);
        imagecopyresampled($temp, $this->img, 0, 0, 0, 0, $tempW, $tempH, $w, $h);
        return($temp);
    }

    /* average_color - PROTECTED gets the mean average color of
                       the region of an image, within a bounding
                       box */
    protected function average_color($img,$x,$y,$w,$h) {
        // make a down sampled 1x1px square from the image
        $colorTemp     = imagecreatetruecolor(1,1);
        imagecopyresampled($colorTemp, $img, 0, 0, $x, $y, 1, 1, $w, $h);
        // get the color of that pixel
        $avgColor      = imagecolorsforindex($colorTemp, imagecolorat($colorTemp,0,0));
        imagedestroy($colorTemp);
        return $avgColor;
    }

    /* euclidean_distance - PROTECTED gets the euclidean distance
                            (in LAB-X Color space) between two RGB
                            colors */
    protected function euclidean_distance($color1, $color2) {
        // convert colors to LAB-X
        $color1 = $this->RGBtoLAB($color1);
        $color2 = $this->RGBtoLAB($color2);
        // euclidean distance
        $sumOfSquares = 0;
        foreach($color1 as $key=>$val) {
            $sumOfSquares += pow(($color2[$key]-$val),2);
        }
        $distance = sqrt($sumOfSquares);
        // divide by ten to put in similar range to entropy numbers
        return ($distance/10);
    }

    /* RGBtoHSV - PROTECTED converts a given color in RGB to HSV
       (yes, I had to google this) */
    protected function RGBtoHSV($color) {
        $R = ($color['red'] / 255);
        $G = ($color['green'] / 255);
        $B = ($color['blue'] / 255);
        $maxRGB = max($R, $G, $B);
        $minRGB = min($R, $G, $B);
        $chroma = $maxRGB - $minRGB;
        $computedV = 100 * $maxRGB;
        if ($chroma == 0)
            return array('h'=>0, 's'=>0, 'v'=>$computedV);
        $computedS = 100 * ($chroma / $maxRGB);
        if ($R == $minRGB) {
            $h = 3 - (($G - $B) / $chroma);
        } elseif ($B == $minRGB) {
            $h = 1 - (($R - $G) / $chroma);
        }else {
            $h = 5 - (($B - $R) / $chroma);
        }
        $computedH = $h*60;
        return array('h'=>$computedH, 's'=>$computedS, 'v'=>$computedV);
    }

    /* RGBtoLAB - PROTECTED converts a given color in RGB to LAB-X Color
       (yes, I had to google this) */
    protected function RGBtoLAB($color) {
        $r = $color['red'  ]/255;
        $g = $color['green']/255;
        $b = $color['blue' ]/255;
        if($r > 0.04045) {
            $r = pow((($r + 0.055) / 1.055), 2.4);
        } else {
            $r = $r / 12.92;
        }
        if($g > 0.04045) {
            $g = pow((($g + 0.055) / 1.055), 2.4);
        } else {
            $g = $g / 12.92;
        }
        if($b > 0.04045) {
            $b = pow((($b + 0.055) / 1.055), 2.4);
        } else {
            $b = $b / 12.92;
        }
        $r *= 100;
        $g *= 100;
        $b *= 100;
        $x  = 0.4124*$r + 0.3576*$g + 0.1805*$b;
        $y  = 0.2126*$r + 0.7152*$g + 0.0722*$b;
        $z  = 0.0193*$r + 0.1192*$g + 0.9505*$b;
        $l  = $a = $b = 0;
        if(!($y == 0)) {
            $l = 10*sqrt($y);
            $a = 17.5*((1.02*$x) - $y)/sqrt($y);
            $b = 7*($y - 0.847*$z)/sqrt($y);
        }
        return array('L'=>$l, 'A'=>$a, 'B'=>$b);
    }

    /* get_entropy - PROTECTED gets the level of entropy present in a slice of an image */
    public function get_entropy($img, $x=0, $y=0, $w=null, $h=null) {
        if($w == null) $w = imageSX($img)-$x;
        if($h == null) $h = imageSY($img)-$y;
        if($w < 1 || $h < 1) return false;
        // create a temp image from our slice
        $temp = imagecreatetruecolor($w,$h);
        imagecopy($temp, $img, 0, 0, $x, $y, $w, $h);
        // make that image a greyscale set of detected edges
        imagefilter($temp, IMG_FILTER_EDGEDETECT);
        imagefilter($temp, IMG_FILTER_GRAYSCALE);
        // create a histogram of the edge image
        $levels = array();
        for($x=0;$x<$w;$x++) {
            for($y=0;$y<$h;$y++) {
                $color = imagecolorsforindex($temp, imagecolorat($temp,$x,$y));
                $grayVal = $color['red'];
                if(!isset($levels[$grayVal]))$levels[$grayVal]=0;
                $levels[$grayVal]++;
            }
        }
        // get entropy value from histogram
        $entropy = 0;
        foreach($levels as $level) {
            $pl = $level/($w*$h);
            $pl = $pl*log($pl);
            $entropy -= $pl;
        }
        imagedestroy($temp);
        return($entropy);
    }

    /* get_array_weight - PRIVATE tells you if the values to the
       left of the index average higher than the values to the
       right, or vice versa, or if they're equally balanced */
    private function get_array_weight($array, $focus) {
        $slices = count($array);
        $a  = $b = 0;
        if($focus == 0) {
            $a  = 0;
            $b = 1;
        } elseif($focus == $slices-1) {
            $a  = 1;
            $b = 0;
        } else {
            // sum values to the left of focus and get mean average
            for($i=0;$i<$focus;$i++) {
                $a  += $array[$i];
            }
            $a = $a/$focus;
            // sum values to the right of focus and get mean average
            for($i=$focus+1;$i<$slices;$i++) {
                $b += $array[$i];
            }
            $b = $b/($slices-($focus+1));
        }
        if($a > $b) return 1;
        if($a < $b) return -1;
        return 0;
    }
}
