<?php

/**
 *    pChart - a PHP class to build charts!
 *    Copyright (C) 2008 Jean-Damien POGOLOTTI
 *    Version 2.0
 *    Copyright (C) 2010 Tim Martin
 *
 *    http://pchart.sourceforge.net
 *
 *    This program is free software: you can redistribute it and/or modify
 *    it under the terms of the GNU General Public License as published by
 *    the Free Software Foundation, either version 1,2,3 of the License, or
 *    (at your option) any later version.
 *
 *    This program is distributed in the hope that it will be useful,
 *    but WITHOUT ANY WARRANTY; without even the implied warranty of
 *    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *    GNU General Public License for more details.
 *
 *    You should have received a copy of the GNU General Public License
 *    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

define ("PIE_PERCENTAGE", 1);
define ("PIE_LABELS", 2);
define ("PIE_NOLABEL", 3);
define ("PIE_PERCENTAGE_LABEL", 4);

/**
 * This is an attempt to separate out the pie chart drawing code from
 * the rest of the chart code, since pie charts are very different
 * from charts that use 2D Cartesian coordinates.
 *
 * The inheritance hierarchy here probably isn't the finished article;
 * separating out in this way is an intermediate form that I hope will
 * shed light on the real dependency structure.
 */
class PieChart extends pChart {
    /**
     * Draw the data legends
     *
     * @param int X-Position
     * @param int Y-Position
     * @param array Data pData->getData
     * @param array DataDescription pData->getDataDescription
     * @param Color
     * @param ShadowProperties
     * @access public
     */
    public function drawPieLegend($XPos, $YPos, $Data, $DataDescription, Color $color, ShadowProperties $shadowProperties = null) {
        if($shadowProperties == null) {
            $shadowProperties = ShadowProperties::FromDefaults();
        }

        /* Validate the Data and DataDescription array */
        $this->validateDataDescription("drawPieLegend", $DataDescription, FALSE);
        $this->validateData("drawPieLegend", $Data);

        if($DataDescription->getPosition() == '')
            return (-1);

        /* <-10->[8]<-4->Text<-10-> */
        $MaxWidth  = 0;
        $MaxHeight = 8;
        foreach($Data as $Key => $Value) {
            $Value2     = $Value [$DataDescription->getPosition()];
            $Position   = imageftbbox($this->FontSize, 0, $this->FontName, $Value2);
            $TextWidth  = $Position [2] - $Position [0];
            $TextHeight = $Position [1] - $Position [7];
            if($TextWidth > $MaxWidth) {
                $MaxWidth = $TextWidth;
            }

            $MaxHeight = $MaxHeight + $TextHeight + 4;
        }
        $MaxHeight = $MaxHeight - 3;
        $MaxWidth  = $MaxWidth + 32;

        $this->canvas->drawFilledRoundedRectangle(
            new Point($XPos + 1, $YPos + 1),
            new Point($XPos + $MaxWidth + 1,
                      $YPos + $MaxHeight + 1),
            5,
            $color->addRGBIncrement(-30),
            $this->LineWidth,
            $this->LineDotSize,
            $shadowProperties
        );

        $this->canvas->drawFilledRoundedRectangle(
            new Point($XPos, $YPos),
            new Point($XPos + $MaxWidth,
                      $YPos + $MaxHeight),
            5, $color,
            $this->LineWidth,
            $this->LineDotSize,
            $shadowProperties
        );

        $YOffset = 4 + $this->FontSize;
        $ID      = 0;
        foreach($Data as $Key => $Value) {
            $Value2     = $Value [$DataDescription->getPosition()];
            $Position   = imageftbbox($this->FontSize, 0, $this->FontName, $Value2);
            $TextHeight = $Position [1] - $Position [7];
            $this->canvas->drawFilledRectangle(
                new Point($XPos + 10,
                          $YPos + $YOffset - 6),
                new Point($XPos + 14,
                          $YPos + $YOffset - 2),
                $this->palette->colors[$ID],
                $shadowProperties
            );

            $this->canvas->drawText(
                $this->FontSize,
                0,
                new Point($XPos + 22,
                          $YPos + $YOffset),
                new Color(0, 0, 0),
                $this->FontName,
                $Value2,
                $shadowProperties
            );
            $YOffset = $YOffset + $TextHeight + 4;
            $ID++;
        }
    }

    /**
     * This function draw a flat pie chart
     *
     * @param array Data (PieChart->getData())
     * @param array Description (PieChart->getDataDescription())
     * @param int X-Position of the Center
     * @param int Y-Position of the Center
     * @param int Radius of the cake
     * @param const int Draw the Labels to the pies? PIE_LABELS, PIE_NOLABEL, PIE_PERCENTAGE, PIE_PERCENATGE_LABEL
     * @param int Distance between the splices
     * @param int number of decimals
     * @param ShadowProperties
     * @access public
     */
    public function drawBasicPieGraph($Data, $DataDescription, $XPos, $YPos, $Radius = 100, $DrawLabels = PIE_NOLABEL, Color $color = null, $Decimals = 0, ShadowProperties $shadowProperties = null) {
        if($shadowProperties == null) {
            $shadowProperties = ShadowProperties::NoShadow();
        }

        if(empty($DataDescription->values)) {
            throw new Exception("No values available in data description in drawBasicPieGraph()");
        }

        if($color == null) {
            $color = new Color(255, 255, 255);
        }

        /* Validate the Data and DataDescription array */
        $this->validateDataDescription("drawBasicPieGraph", $DataDescription, FALSE);
        $this->validateData("drawBasicPieGraph", $Data);

        /* Determine pie sum */
        $Series = 0;
        $PieSum = 0;
        foreach($DataDescription->values as $Key2 => $ColName) {
            if($ColName != $DataDescription->getPosition()) {
                $Series++;
                foreach(array_keys($Data) as $Key) {
                    if(isset ($Data [$Key] [$ColName]))
                        $PieSum = $PieSum + $Data [$Key] [$ColName];
                    $iValues [] = $Data [$Key] [$ColName];
                }
            }
        }

        /* Validate serie */
        if($Series != 1)
            throw new Exception("Pie chart can only accept one serie of data.");
        /** @todo Proper exception type needed here */

        /* Check for division by zero */
        if($PieSum == 0) {
            // When all data values are zero, create a single slice representing "No Data"
            $SpliceRatio   = 360;
            $SplicePercent = 100;
            // Reset iValues to have a single 1 value to represent the full circle
            $iValues = array(1);
        } else {
            $SpliceRatio   = 360 / $PieSum;
            $SplicePercent = 100 / $PieSum;
        }

        /* Calculate all polygons */
        $Angle    = 0;
        $TopPlots = [];
        foreach($iValues as $Key => $Value) {
            $TopPlots [$Key] [] = $XPos;
            $TopPlots [$Key] [] = $YPos;

            /* Process labels position & size */
            $this->processLabelsPositionAndSize($DrawLabels, $Angle, $Value, $SpliceRatio, $SplicePercent, 0, $Decimals, $Radius, $XPos, $YPos, $shadowProperties);

            /* Process pie slices */
            for($iAngle = $Angle; $iAngle <= $Angle + $Value * $SpliceRatio; $iAngle = $iAngle + .5) {
                $TopX = cos($iAngle * M_PI / 180) * $Radius + $XPos;
                $TopY = sin($iAngle * M_PI / 180) * $Radius + $YPos;

                $TopPlots [$Key] [] = $TopX;
                $TopPlots [$Key] [] = $TopY;
            }

            $TopPlots [$Key] [] = $XPos;
            $TopPlots [$Key] [] = $YPos;

            $Angle = $iAngle;
        }
        $PolyPlots = $TopPlots;

        /* Set array values type to float --- PHP Bug with
           * imagefilledpolygon casting to integer */
        foreach($TopPlots as $Key => $Value) {
            foreach(array_keys($TopPlots[$Key]) as $Key2) {
                settype($TopPlots [$Key] [$Key2], "float");
            }
        }

        /* Draw Top polygons */
        foreach($PolyPlots as $Key => $Value) {
            $this->canvas->drawFilledPolygon(
                $PolyPlots [$Key],
                (count($PolyPlots [$Key]) + 1) / 2,
                $this->palette->colors[$Key]
            );
        }

        $this->canvas->drawCircle(
            new Point($XPos - .5, $YPos - .5),
            $Radius,
            $color,
            $shadowProperties
        );
        $this->canvas->drawCircle(
            new Point($XPos - .5, $YPos - .5),
            $Radius + .5,
            $color,
            $shadowProperties
        );

        /* Draw Top polygons */
        foreach($TopPlots as $Key => $Value) {
            for($j = 0; $j <= count($TopPlots [$Key]) - 4; $j = $j + 2)
                $this->canvas->drawLine(
                    new Point($TopPlots [$Key] [$j],
                              $TopPlots [$Key] [$j + 1]),
                    new Point($TopPlots [$Key] [$j + 2],
                              $TopPlots [$Key] [$j + 3]),
                    $color,
                    $this->LineWidth,
                    $this->LineDotSize,
                    $shadowProperties
                );
        }
    }

    /**
     * @todo This method was generated by pulling out a bunch of
     * copy&paste duplication. It needs further work to improve the
     * interface.
     */
    private function processLabelsPositionAndSize($DrawLabels, $Angle, $Value, $SpliceRatio, $SplicePercent, $SpliceDistance, $Decimals, $Radius, $XPos, $YPos, ShadowProperties $shadowProperties) {
        $Caption = "";
        if(!($DrawLabels == PIE_NOLABEL)) {
            $TAngle = $Angle + ($Value * $SpliceRatio / 2);
            if($DrawLabels == PIE_PERCENTAGE)
                $Caption = (round($Value * pow(10, $Decimals) * $SplicePercent) / pow(10, $Decimals))."%";
            elseif($DrawLabels == PIE_LABELS)
                $Caption = $iLabels [$Key];
            elseif($DrawLabels == PIE_PERCENTAGE_LABEL)
                $Caption = $iLabels [$Key]."\r\n".(round($Value * pow(10, $Decimals) * $SplicePercent) / pow(10, $Decimals))."%";
            elseif($DrawLabels == PIE_PERCENTAGE_LABEL)
                $Caption = $iLabels [$Key]."\r\n".(round($Value * pow(10, $Decimals) * $SplicePercent) / pow(10, $Decimals))."%";

            $Position   = imageftbbox($this->FontSize, 0, $this->FontName, $Caption);
            $TextWidth  = $Position [2] - $Position [0];
            $TextHeight = abs($Position [1]) + abs($Position [3]);

            $TX = cos(($TAngle) * M_PI / 180) * ($Radius + 10 + $SpliceDistance) + $XPos;

            if($TAngle > 0 && $TAngle < 180)
                $TY = sin(($TAngle) * M_PI / 180) * ($Radius + 10 + $SpliceDistance) + $YPos + 4;
            else
                $TY = sin(($TAngle) * M_PI / 180) * ($Radius + $SpliceDistance + 4) + $YPos - ($TextHeight / 2);

            if($TAngle > 90 && $TAngle < 270)
                $TX = $TX - $TextWidth;

            $this->canvas->drawText(
                $this->FontSize,
                0,
                new Point($TX, $TY),
                new Color(70, 70, 70),
                $this->FontName,
                $Caption,
                $shadowProperties
            );
        }
    }

    /**
     * This function draw a simple flat pie graph with shadows
     *
     * @param array Data (PieChart->getData())
     * @param array Description (PieChart->getDataDescription())
     * @param int X-Position of the Center
     * @param int Y-Position of the Center
     * @param int Radius of the cake
     * @param const int Draw the Labels to the pies? PIE_LABELS, PIE_NOLABEL, PIE_PERCENTAGE, PIE_PERCENATGE_LABEL
     * @param int Distance between the splices
     * @param int number of decimals
     * @param ShadowProperties
     * @access public
     */
    public function drawFlatPieGraphWithShadow($Data, $DataDescription, $XPos, $YPos, $Radius = 100, $DrawLabels = PIE_NOLABEL, $SpliceDistance = 0, $Decimals = 0, ShadowProperties $shadowProperties = NULL) {
        /**
         * @todo Slightly ugly code follows: We want to draw the graph
         * with once to be the 'shadow', without itself having a
         * shadow, and once again to be the actual graph. In fact, we
         * can't pass ShadowProperties::NoShadow() into the first
         * drawFlatPieGraph() call, since the method expects to use
         * the color on the shadow properties, even though it is
         * inactive. We do a clone to avoid mucking with the caller's
         * copy of the shadow properties.
         */
        $inactiveShadowProperties         = ShadowProperties::Copy($shadowProperties);
        $inactiveShadowProperties->active = false;

        $this->drawFlatPieGraph(
            $Data,
            $DataDescription,
            $XPos + $shadowProperties->xDistance,
            $YPos + $shadowProperties->yDistance,
            $Radius,
            PIE_NOLABEL,
            $SpliceDistance, $Decimals, TRUE,
            $inactiveShadowProperties
        );
        $this->drawFlatPieGraph(
            $Data, $DataDescription, $XPos, $YPos, $Radius, $DrawLabels, $SpliceDistance, $Decimals, FALSE,
            $inactiveShadowProperties
        );
    }

    /**
     * This function draw a simple flat pie graph with shadows
     *
     * @param array Data (PieChart->getData())
     * @param array Description (PieChart->getDataDescription())
     * @param int X-Position of the Center
     * @param int Y-Position of the Center
     * @param int Radius of the cake
     * @param const int Draw the Labels to the pies? PIE_LABELS, PIE_NOLABEL, PIE_PERCENTAGE, PIE_PERCENATGE_LABEL
     * @param int Distance between the splices
     * @param int number of decimals
     * @param bool Should the Chart be gray?
     * @param ShadowProperties
     * @access public
     */
    public function drawFlatPieGraph($Data, $DataDescription, $XPos, $YPos, $Radius = 100, $DrawLabels = PIE_NOLABEL, $SpliceDistance = 0, $Decimals = 0, $AllBlack = FALSE, ShadowProperties $shadowProperties = null) {
        if($shadowProperties == null) {
            $shadowProperties = ShadowProperties::FromDefaults();
        }

        /* Validate the Data and DataDescription array */
        $this->validateDataDescription("drawFlatPieGraph", $DataDescription, FALSE);
        $this->validateData("drawFlatPieGraph", $Data);

        /* Determine pie sum */
        $Series = 0;
        $PieSum = 0;
        foreach($DataDescription->values as $ColName) {
            if($ColName != $DataDescription->getPosition()) {
                $Series++;
                foreach(array_keys($Data) as $Key) {
                    if(isset ($Data [$Key] [$ColName]))
                        $PieSum = $PieSum + $Data [$Key] [$ColName];
                    $iValues [] = $Data [$Key] [$ColName];
                }
            }
        }

        /* Validate serie */
        if($Series != 1) {
            /**
             * @todo Proper exception type needed here
             */
            throw new Exception("Pie chart can only accept one serie of data.");
        }

        $SpliceRatio   = 360 / $PieSum;
        $SplicePercent = 100 / $PieSum;

        /* Calculate all polygons */
        $Angle    = 0;
        $TopPlots = "";
        foreach($iValues as $Key => $Value) {
            $XOffset = cos(($Angle + ($Value / 2 * $SpliceRatio)) * M_PI / 180) * $SpliceDistance;
            $YOffset = sin(($Angle + ($Value / 2 * $SpliceRatio)) * M_PI / 180) * $SpliceDistance;

            $TopPlots [$Key] [] = round($XPos + $XOffset);
            $TopPlots [$Key] [] = round($YPos + $YOffset);

            if($AllBlack) {
                $color = $shadowProperties->color;
            } else {
                $color = $this->palette->colors[$Key];
            }

            /* Process labels position & size */
            $this->processLabelsPositionAndSize($DrawLabels, $Angle, $Value, $SpliceRatio, $SplicePercent, $SpliceDistance, $Decimals, $Radius, $XPos, $YPos, $shadowProperties);

            /* Process pie slices */
            $this->processPieSlices($Angle, $SpliceRatio, $Value, $Radius, $XPos, $YPos, $XOffset, $YOffset, $color, $TopPlots[$Key], $shadowProperties);

            $TopPlots [$Key] [] = round($XPos + $XOffset);
            $TopPlots [$Key] [] = round($YPos + $YOffset);
        }
        $PolyPlots = $TopPlots;

        /* Draw Top polygons */
        foreach($PolyPlots as $Key => $Value) {
            if(!$AllBlack)
                $polygonColor = $this->palette->colors[$Key];
            else
                $polygonColor = $shadowProperties->color;

            $this->canvas->drawFilledPolygon(
                $PolyPlots [$Key],
                (count($PolyPlots [$Key]) + 1) / 2,
                $polygonColor
            );
        }
    }

    /**
     * This function draw a pseudo-3D pie chart
     *
     * @param pData
     * @param int X-Position of the Center
     * @param int Y-Position of the Center
     * @param int Radius of the cake
     * @param const int Draw the Labels to the pies? PIE_LABELS, PIE_NOLABEL, PIE_PERCENTAGE, PIE_PERCENATGE_LABEL
     * @param bool Enhance colors?
     * @param int Skew
     * @param int Height of the splices
     * @param int Distance between the splices
     * @param int number of decimals
     * @param ShadowProperties
     * @access public
     */
    public function drawPieGraph(pData $data, $XPos, $YPos,
                                 $Radius = 100, $DrawLabels = PIE_NOLABEL,
                                 $EnhanceColors = TRUE, $Skew = 60,
                                 $SpliceHeight = 20, $SpliceDistance = 0,
                                 $Decimals = 0,
                                 ShadowProperties $shadowProperties = null) {
        if($shadowProperties == null) {
            $shadowProperties = ShadowProperties::FromDefaults();
        }

        /* Validate the Data and DataDescription array */
        $this->validateDataDescription("drawPieGraph", $data->getDataDescription(), FALSE);
        $this->validateData("drawPieGraph", $data->getData());

        /* Determine pie sum */
        $Series  = 0;
        $PieSum  = 0;
        $rPieSum = 0;
        foreach($data->getDataDescription()->values as $ColName) {
            if($ColName != $data->getDataDescription()->getPosition()) {
                $Series++;
                $dataArray = $data->getData();
                foreach(array_keys($dataArray) as $Key) {
                    if(isset ($dataArray[$Key] [$ColName])) {
                        if($dataArray[$Key] [$ColName] == 0) {
                            $iValues [] = 0;
                            $rValues [] = 0;
                            $iLabels [] = $dataArray[$Key] [$data->getDataDescription()->getPosition()];
                        } // Removed : $PieSum++; $rValues[] = 1;
                        else {
                            $PieSum += $dataArray[$Key] [$ColName];
                            $iValues [] = $dataArray[$Key] [$ColName];
                            $iLabels [] = $dataArray[$Key] [$data->getDataDescription()->getPosition()];
                            $rValues [] = $dataArray[$Key] [$ColName];
                            $rPieSum += $dataArray[$Key] [$ColName];
                        }
                    }
                }
            }
        }

        /* Validate serie */
        if($Series != 1)
            throw new Exception("Pie chart can only accept one serie of data.");
        /** @todo Proper exception type needed here */

        $SpliceDistanceRatio = $SpliceDistance;
        $SkewHeight          = ($Radius * $Skew) / 100;
        $SpliceRatio         = (360 - $SpliceDistanceRatio * count($iValues)) / $PieSum;
        $SplicePercent       = 100 / $PieSum;
        $rSplicePercent      = 100 / $rPieSum;

        /* Calculate all polygons */
        $Angle     = 0;
        $CDev      = 5;
        $TopPlots  = "";
        $BotPlots  = "";
        $aTopPlots = "";
        $aBotPlots = "";
        foreach($iValues as $Key => $Value) {
            $XCenterPos  = cos(($Angle - $CDev + ($Value * $SpliceRatio + $SpliceDistanceRatio) / 2) * M_PI / 180) * $SpliceDistance + $XPos;
            $YCenterPos  = sin(($Angle - $CDev + ($Value * $SpliceRatio + $SpliceDistanceRatio) / 2) * M_PI / 180) * $SpliceDistance + $YPos;
            $XCenterPos2 = cos(($Angle + $CDev + ($Value * $SpliceRatio + $SpliceDistanceRatio) / 2) * M_PI / 180) * $SpliceDistance + $XPos;
            $YCenterPos2 = sin(($Angle + $CDev + ($Value * $SpliceRatio + $SpliceDistanceRatio) / 2) * M_PI / 180) * $SpliceDistance + $YPos;

            $TopPlots [$Key] []  = round($XCenterPos);
            $BotPlots [$Key] []  = round($XCenterPos);
            $TopPlots [$Key] []  = round($YCenterPos);
            $BotPlots [$Key] []  = round($YCenterPos + $SpliceHeight);
            $aTopPlots [$Key] [] = $XCenterPos;
            $aBotPlots [$Key] [] = $XCenterPos;
            $aTopPlots [$Key] [] = $YCenterPos;
            $aBotPlots [$Key] [] = $YCenterPos + $SpliceHeight;

            /* Process labels position & size */
            $Caption = "";
            if(!($DrawLabels == PIE_NOLABEL)) {
                $TAngle = $Angle + ($Value * $SpliceRatio / 2);
                if($DrawLabels == PIE_PERCENTAGE)
                    $Caption = (round($rValues [$Key] * pow(10, $Decimals) * $rSplicePercent) / pow(10, $Decimals))."%";
                elseif($DrawLabels == PIE_LABELS)
                    $Caption = $iLabels [$Key];
                elseif($DrawLabels == PIE_PERCENTAGE_LABEL)
                    $Caption = $iLabels [$Key]."\r\n".(round($Value * pow(10, $Decimals) * $SplicePercent) / pow(10, $Decimals))."%";

                $Position   = imageftbbox($this->FontSize, 0, $this->FontName, $Caption);
                $TextWidth  = $Position [2] - $Position [0];
                $TextHeight = abs($Position [1]) + abs($Position [3]);

                $TX = cos(($TAngle) * M_PI / 180) * ($Radius + 10) + $XPos;

                if($TAngle > 0 && $TAngle < 180)
                    $TY = sin(($TAngle) * M_PI / 180) * ($SkewHeight + 10) + $YPos + $SpliceHeight + 4;
                else
                    $TY = sin(($TAngle) * M_PI / 180) * ($SkewHeight + 4) + $YPos - ($TextHeight / 2);

                if($TAngle > 90 && $TAngle < 270)
                    $TX = $TX - $TextWidth;

                $this->canvas->drawText(
                    $this->FontSize,
                    0,
                    new Point($TX, $TY),
                    new Color(70, 70, 70),
                    $this->FontName,
                    $Caption,
                    $shadowProperties
                );
            }

            /* Process pie slices */
            for($iAngle = $Angle; $iAngle <= $Angle + $Value * $SpliceRatio; $iAngle = $iAngle + .5) {
                $TopX = cos($iAngle * M_PI / 180) * $Radius + $XPos;
                $TopY = sin($iAngle * M_PI / 180) * $SkewHeight + $YPos;

                $TopPlots [$Key] []  = round($TopX);
                $BotPlots [$Key] []  = round($TopX);
                $TopPlots [$Key] []  = round($TopY);
                $BotPlots [$Key] []  = round($TopY + $SpliceHeight);
                $aTopPlots [$Key] [] = $TopX;
                $aBotPlots [$Key] [] = $TopX;
                $aTopPlots [$Key] [] = $TopY;
                $aBotPlots [$Key] [] = $TopY + $SpliceHeight;
            }

            $TopPlots [$Key] []  = round($XCenterPos2);
            $BotPlots [$Key] []  = round($XCenterPos2);
            $TopPlots [$Key] []  = round($YCenterPos2);
            $BotPlots [$Key] []  = round($YCenterPos2 + $SpliceHeight);
            $aTopPlots [$Key] [] = $XCenterPos2;
            $aBotPlots [$Key] [] = $XCenterPos2;
            $aTopPlots [$Key] [] = $YCenterPos2;
            $aBotPlots [$Key] [] = $YCenterPos2 + $SpliceHeight;

            $Angle = $iAngle + $SpliceDistanceRatio;
        }

        $this->drawPieGraphBottomPolygons(
            $iValues, $BotPlots,
            $EnhanceColors, $aBotPlots,
            $shadowProperties
        );

        $this->drawPieGraphLayers(
            $iValues, $TopPlots, $EnhanceColors,
            $SpliceHeight, $this->palette, $shadowProperties
        );

        $this->drawPieGraphTopPolygons(
            $iValues, $TopPlots, $EnhanceColors,
            $aTopPlots, $shadowProperties
        );
    }

    /**
     * @todo Not really sure what this does, it appears to do several
     * things at once (it was generated by pulling code out of
     * drawPieChart())
     */
    private function processPieSlices(& $Angle, $SpliceRatio, $Value, $Radius, $XPos, $YPos, $XOffset, $YOffset, Color $color, array & $plotArray, ShadowProperties $shadowProperties) {
        $lastPos = null;

        for($iAngle = $Angle; $iAngle <= $Angle + $Value * $SpliceRatio; $iAngle = $iAngle + .5) {
            $PosX = cos($iAngle * M_PI / 180) * $Radius + $XPos + $XOffset;
            $PosY = sin($iAngle * M_PI / 180) * $Radius + $YPos + $YOffset;

            $plotArray[] = round($PosX);
            $plotArray[] = round($PosY);

            $currentPos = new Point($PosX, $PosY);

            if($iAngle == $Angle || $iAngle == $Angle + $Value * $SpliceRatio || $iAngle + .5 > $Angle + $Value * $SpliceRatio)
                $this->canvas->drawLine(
                    new Point($XPos + $XOffset, $YPos + $YOffset),
                    $currentPos,
                    $color,
                    $this->LineWidth,
                    $this->LineDotSize,
                    $shadowProperties
                );

            if($lastPos != null)
                $this->canvas->drawLine(
                    $lastPos,
                    $currentPos,
                    $color,
                    $this->LineWidth,
                    $this->LineDotSize,
                    $shadowProperties
                );

            $lastPos = $currentPos;
        }

        /* Update the angle in the caller to the final angle we
           * reached while processing */
        $Angle = $iAngle;
    }

    private function drawPieGraphBottomPolygons(array $iValues, array $BotPlots, $EnhanceColors, array $aBotPlots, ShadowProperties $shadowProperties) {
        foreach(array_keys($iValues) as $Key) {
            $this->canvas->drawFilledPolygon(
                $BotPlots [$Key],
                (count($BotPlots [$Key]) + 1) / 2,
                $this->palette->colors[$Key]->addRGBIncrement(-20)
            );

            if($EnhanceColors) {
                $En = -10;
            } else {
                $En = 0;
            }

            for($j = 0; $j <= count($aBotPlots [$Key]) - 4; $j = $j + 2) {
                $this->canvas->drawLine(
                    new Point($aBotPlots [$Key] [$j],
                              $aBotPlots [$Key] [$j + 1]),
                    new Point($aBotPlots [$Key] [$j + 2],
                              $aBotPlots [$Key] [$j + 3]),
                    $this->palette->colors[$Key]->addRGBIncrement($En),
                    $this->LineWidth,
                    $this->LineDotSize,
                    $shadowProperties
                );
            }
        }
    }

    private function drawPieGraphLayers($iValues, $TopPlots, $EnhanceColors, $SpliceHeight, Palette $palette, ShadowProperties $shadowProperties) {
        if($EnhanceColors) {
            $ColorRatio = 30 / $SpliceHeight;
        } else {
            $ColorRatio = 25 / $SpliceHeight;
        }
        for($i = $SpliceHeight - 1; $i >= 1; $i--) {
            foreach(array_keys($iValues) as $Key) {
                $Plots = "";
                $Plot  = 0;
                foreach($TopPlots [$Key] as $Value2) {
                    $Plot++;
                    if($Plot % 2 == 1)
                        $Plots [] = $Value2;
                    else
                        $Plots [] = $Value2 + $i;
                }
                $this->canvas->drawFilledPolygon(
                    $Plots,
                    (count($Plots) + 1) / 2,
                    $palette->colors[$Key]->addRGBIncrement(-10)
                );

                $Index = count($Plots);
                if($EnhanceColors) {
                    $ColorFactor = -20 + ($SpliceHeight - $i) * $ColorRatio;
                } else {
                    $ColorFactor = 0;
                }

                $this->canvas->drawAntialiasPixel(
                    new Point($Plots[0], $Plots[1]),
                    $palette->colors[$Key]->addRGBIncrement($ColorFactor),
                    $shadowProperties
                );

                $this->canvas->drawAntialiasPixel(
                    new Point($Plots[2], $Plots[3]),
                    $palette->colors[$Key]->addRGBIncrement($ColorFactor),
                    $shadowProperties
                );

                $this->canvas->drawAntialiasPixel(
                    new Point($Plots[$Index - 4], $Plots [$Index - 3]),
                    $palette->colors[$Key]->addRGBIncrement($ColorFactor),
                    $shadowProperties
                );
            }
        }
    }

    /**
     * @brief Draw the polygons that form the top of a 3D pie chart
     */
    private function drawPieGraphTopPolygons($iValues, $TopPlots, $EnhanceColors, $aTopPlots, ShadowProperties $shadowProperties) {
        for($Key = count($iValues) - 1; $Key >= 0; $Key--) {
            $this->canvas->drawFilledPolygon(
                $TopPlots [$Key],
                (count($TopPlots [$Key]) + 1) / 2,
                $this->palette->colors[$Key]
            );

            if($EnhanceColors) {
                $En = 10;
            } else {
                $En = 0;
            }
            for($j = 0; $j <= count($aTopPlots [$Key]) - 4; $j = $j + 2)
                $this->canvas->drawLine(
                    new Point($aTopPlots[$Key][$j],
                              $aTopPlots[$Key][$j + 1]),
                    new Point($aTopPlots [$Key] [$j + 2],
                              $aTopPlots [$Key] [$j + 3]),
                    $this->palette->colors[$Key]->addRGBIncrement($En),
                    $this->LineWidth,
                    $this->LineDotSize,
                    $shadowProperties
                );
        }
    }
}
