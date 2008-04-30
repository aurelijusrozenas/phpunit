<?php
/**
 * PHPUnit
 *
 * Copyright (c) 2002-2008, Sebastian Bergmann <sb@sebastian-bergmann.de>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Sebastian Bergmann nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @category   Testing
 * @package    PHPUnit
 * @author     Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @copyright  2002-2008 Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version    SVN: $Id$
 * @link       http://www.phpunit.de/
 * @since      File available since Release 3.3.0
 */

require_once 'PHPUnit/Runner/Version.php';
require_once 'PHPUnit/Util/Metrics/File.php';
require_once 'PHPUnit/Util/Class.php';
require_once 'PHPUnit/Util/CodeCoverage.php';
require_once 'PHPUnit/Util/Filter.php';
require_once 'PHPUnit/Util/XML.php';

PHPUnit_Util_Filter::addFileToFilter(__FILE__, 'PHPUNIT');

/**
 * Writes one XML file per covered PHP source file to a given directory.
 * Each <line> element holds a line of PHP sourcecode that is annotated with
 * code coverage information.
 *
 * @category   Testing
 * @package    PHPUnit
 * @author     Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @copyright  2002-2008 Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version    Release: @package_version@
 * @link       http://www.phpunit.de/
 * @since      Class available since Release 3.3.0
 */
class PHPUnit_Util_Log_CodeCoverage_XML_Source
{
    protected $directory;

    /**
     * @param  string $directory
     * @access public
     */
    public function __construct($directory)
    {
        $this->directory = PHPUnit_Util_Filesystem::getDirectory($directory);
    }

    /**
     * @param  PHPUnit_Framework_TestResult $result
     * @access public
     */
    public function process(PHPUnit_Framework_TestResult $result)
    {
        $sutData   = $result->getCodeCoverageInformation();
        $sutFiles  = PHPUnit_Util_CodeCoverage::getSummary($sutData, TRUE);
        $allData   = $result->getCodeCoverageInformation(FALSE);
        $allFiles  = PHPUnit_Util_CodeCoverage::getSummary($allData, TRUE);
        $testFiles = array_diff(array_keys($allFiles), array_keys($sutFiles));

        foreach (array_keys($allFiles) as $key) {
            if (!in_array($key, $testFiles)) {
                unset($allFiles[$key]);
            }
        }

        $allCommonPath = PHPUnit_Util_Filesystem::reducePaths($allFiles);
        $sutCommonPath = PHPUnit_Util_Filesystem::reducePaths($sutFiles);
        $testFiles     = $allFiles;
        $time          = time();

        unset($allData);
        unset($allFiles);
        unset($sutData);

        foreach ($sutFiles as $filename => $data) {
            $fullPath = $sutCommonPath . DIRECTORY_SEPARATOR . $filename;

            if (file_exists($fullPath)) {
                $document = new DOMDocument('1.0', 'UTF-8');
                $document->formatOutput = TRUE;

                $coveredFile = $document->createElement('coveredFile');
                $coveredFile->setAttribute('fullPath', $fullPath);
                $coveredFile->setAttribute('shortenedPath', $filename);
                $coveredFile->setAttribute('generated', $time);
                $coveredFile->setAttribute('phpunit', PHPUnit_Runner_Version::id());
                $document->appendChild($coveredFile);

                $lines   = file($fullPath, FILE_IGNORE_NEW_LINES);
                $lineNum = 1;

                foreach ($lines as $line) {
                    if (isset($data[$lineNum])) {
                        if (is_array($data[$lineNum])) {
                            $count = count($data[$lineNum]);
                        } else {
                            $count = $data[$lineNum];
                        }
                    } else {
                        $count = -3;
                    }

                    $xmlLine = $coveredFile->appendChild(
                      $document->createElement('line')
                    );

                    $xmlLine->setAttribute('lineNumber', $lineNum);
                    $xmlLine->setAttribute('executed', $count);

                    $xmlLineBody = $xmlLine->appendChild(
                      $document->createElement('body')
                    );

                    $xmlLineBody->appendChild(
                      $document->createCDATASection(
                        PHPUnit_Util_XML::convertToUtf8($line)
                      )
                    );

                    if (isset($data[$lineNum]) && is_array($data[$lineNum])) {
                        $xmlTests = $document->createElement('tests');
                        $xmlLine->appendChild($xmlTests);

                        foreach ($data[$lineNum] as $test) {
                            $xmlTest = $xmlTests->appendChild(
                              $document->createElement('test')
                            );

                            $xmlTest->setAttribute('name', $test->getName());

                            if ($test instanceof PHPUnit_Framework_TestCase) {
                                $xmlTest->setAttribute('status', $test->getStatus());

                                if ($test->hasFailed()) {
                                    $xmlMessage = $xmlTest->appendChild(
                                      $document->createElement('message')
                                    );

                                    $xmlMessage->appendChild(
                                      $document->createCDATASection(
                                        PHPUnit_Util_XML::convertToUtf8($test->getStatusMessage())
                                      )
                                    );
                                }

                                $class      = new ReflectionClass($test);
                                $methodName = $test->getName();

                                if ($class->hasMethod($methodName)) {
                                    $method = $class->getMethod($test->getName());

                                    $xmlTest->setAttribute('class', $class->getName());
                                    $xmlTest->setAttribute('fullPath', $class->getFileName());
                                    $xmlTest->setAttribute('shortenedPath', str_replace($allCommonPath, '', $class->getFileName()));
                                    $xmlTest->setAttribute('line', $method->getStartLine());
                                }
                            }
                        }
                    }

                    $lineNum++;
                }

                $document->save(
                  sprintf(
                    '%s%s.xml',

                    $this->directory,
                    PHPUnit_Util_Filesystem::getSafeFilename(
                      str_replace(DIRECTORY_SEPARATOR, '_', $filename)
                    )
                  )
                );
            }
        }

        foreach ($testFiles as $filename => $data) {
            $fullPath = $allCommonPath . DIRECTORY_SEPARATOR . $filename;

            if (file_exists($fullPath)) {
                $document = new DOMDocument('1.0', 'UTF-8');
                $document->formatOutput = TRUE;

                $testFile = $document->createElement('testFile');
                $testFile->setAttribute('fullPath', $fullPath);
                $testFile->setAttribute('shortenedPath', $filename);
                $testFile->setAttribute('generated', $time);
                $testFile->setAttribute('phpunit', PHPUnit_Runner_Version::id());
                $document->appendChild($testFile);

                $lines   = file($fullPath, FILE_IGNORE_NEW_LINES);
                $lineNum = 1;

                foreach ($lines as $line) {
                    $xmlLine = $testFile->appendChild(
                      $document->createElement('line')
                    );

                    $xmlLine->setAttribute('lineNumber', $lineNum);

                    $xmlLineBody = $xmlLine->appendChild(
                      $document->createElement('body')
                    );

                    $xmlLineBody->appendChild(
                      $document->createCDATASection(
                        PHPUnit_Util_XML::convertToUtf8($line)
                      )
                    );

                    $lineNum++;
                }

                $document->save(
                  sprintf(
                    '%s%s.xml',

                    $this->directory,
                    PHPUnit_Util_Filesystem::getSafeFilename(
                      str_replace(DIRECTORY_SEPARATOR, '_', $filename)
                    )
                  )
                );
            }
        }
    }
}
?>
