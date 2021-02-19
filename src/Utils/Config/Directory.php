<?php

/**
 * This file is part of phiremock-codeception-extension.
 *
 * phiremock-codeception-extension is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * phiremock-codeception-extension is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with phiremock-codeception-extension.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Mcustiel\Phiremock\Server\Utils\Config;

use InvalidArgumentException;

class Directory
{
    /** @var string */
    private $directory;

    public function __construct(string $directory)
    {
        $this->ensureIsDirectory($directory);
        $this->directory = rtrim($directory, \DIRECTORY_SEPARATOR);
    }

    public function asString(): string
    {
        return $this->directory;
    }

    public function getFullSubpathAsString(string $subPath): string
    {
        return $this->directory . \DIRECTORY_SEPARATOR . $subPath;
    }

    private function ensureIsDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a directory or is not accessible.', $directory));
        }
    }
}
