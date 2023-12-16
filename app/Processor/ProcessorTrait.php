<?php
/*
 * Copyright (c) 2022 Sander Dorigo
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace App\Processor;

use Carbon\Carbon;

trait ProcessorTrait
{
    /**
     * @param Carbon $date
     * @param string $type
     * @param string $destination
     *
     * @return string
     */
    protected function getFileName(Carbon $date, string $type, string $destination): string
    {
        $formatted = $date->format('Y-m-d-H-i');
        $filename  = sprintf('%s-%s.md', $formatted, $type);
        $full      = sprintf('%s/%s', $destination, $filename);
        if (!file_exists($full)) {
            return $full;
        }
        $index      = 1;
        $fileExists = true;
        while ($index < 30 && $fileExists) {
            $filename   = sprintf('%s-%s-%d.md', $formatted, $type, $index);
            $full       = sprintf('%s/%s', $destination, $filename);
            $fileExists = file_exists($full);
            $index++;
        }
        return $full;
    }

}
