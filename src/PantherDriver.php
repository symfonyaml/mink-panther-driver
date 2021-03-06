<?php
declare(strict_types=1);

/*
 * This file is part of the Behat\Mink.
 * (c) Robert Freigang <robert.freigang@gmx.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Behat\Mink\Driver;

use Behat\Mink\Exception\DriverException;
use Behat\Mink\Exception\UnsupportedDriverActionException;
use Facebook\WebDriver\Interactions\Internal\WebDriverCoordinates;
use Facebook\WebDriver\Interactions\WebDriverActions;
use Facebook\WebDriver\Internal\WebDriverLocatable;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverHasInputDevices;
use Facebook\WebDriver\WebDriverKeys;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\BrowserKit\Response;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\DomCrawler\Crawler;
use Symfony\Component\Panther\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Panther\DomCrawler\Field\FileFormField;
use Symfony\Component\Panther\DomCrawler\Field\InputFormField;
use Symfony\Component\Panther\DomCrawler\Field\TextareaFormField;
use Symfony\Component\Panther\PantherTestCaseTrait;

/**
 * Symfony2 Panther driver.
 *
 * @author Robert Freigang <robertfreigang@gmx.de>
 */
class PantherDriver extends CoreDriver
{
    use PantherTestCaseTrait;

    /** @var Client */
    private $client;
    private $started = false;
    private $removeScriptFromUrl = false;
    private $removeHostFromUrl = false;
    /** @var array */
    private $options;

    public function __construct(
        array $options = []
    ) {
        $this->options = $options;
    }

    /**
     * Returns BrowserKit HTTP client instance.
     *
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Tells driver to remove hostname from URL.
     *
     * @param Boolean $remove
     *
     * @deprecated Deprecated as of 1.2, to be removed in 2.0. Pass the base url in the constructor instead.
     */
    public function setRemoveHostFromUrl($remove = true)
    {
        @trigger_error(
            'setRemoveHostFromUrl() is deprecated as of 1.2 and will be removed in 2.0. Pass the base url in the constructor instead.',
            E_USER_DEPRECATED
        );
        $this->removeHostFromUrl = (bool)$remove;
    }

    /**
     * Tells driver to remove script name from URL.
     *
     * @param Boolean $remove
     *
     * @deprecated Deprecated as of 1.2, to be removed in 2.0. Pass the base url in the constructor instead.
     */
    public function setRemoveScriptFromUrl($remove = true)
    {
        @trigger_error(
            'setRemoveScriptFromUrl() is deprecated as of 1.2 and will be removed in 2.0. Pass the base url in the constructor instead.',
            E_USER_DEPRECATED
        );
        $this->removeScriptFromUrl = (bool)$remove;
    }

    /**
     * {@inheritdoc}
     */
    public function start()
    {
        $this->client = self::createPantherClient($this->options);
        $this->client->start();

        $this->started = true;
    }

    /**
     * {@inheritdoc}
     */
    public function isStarted()
    {
        return $this->started;
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->client->quit();
        self::stopWebServer();
        $this->started = false;
    }

    /**
     * {@inheritdoc}
     */
    public function reset()
    {
        // experimental
        // $useSpeedUp = false;
        $useSpeedUp = true;
        if ($useSpeedUp) {
            $this->client->getWebDriver()->manage()->deleteAllCookies();
            $history = $this->client->getHistory();
            if ($history) {
                $history->clear();
            }
            // not sure if we should also close all windows
            // $lastWindowHandle = \end($this->client->getWindowHandles());
            // if ($lastWindowHandle) {
            //     $this->client->switchTo()->window($lastWindowHandle);
            // }
            // $this->client->getWebDriver()->navigate()->refresh();
            // $this->client->refreshCrawler();
            // if (\count($this->client->getWindowHandles()) > 1) {
            //     $this->client->getWebDriver()->close();
            // }
        } else {
            // Restarting the client resets the cookies and the history
            $this->client->restart();
        }

    }

    /**
     * {@inheritdoc}
     */
    public function visit($url)
    {
        $this->client->get($this->prepareUrl($url));
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentUrl()
    {
        return $this->client->getCurrentURL();
    }

    /**
     * {@inheritdoc}
     */
    public function reload()
    {
        $this->client->reload();
    }

    /**
     * {@inheritdoc}
     */
    public function forward()
    {
        $this->client->forward();
    }

    /**
     * {@inheritdoc}
     */
    public function back()
    {
        $this->client->back();
    }

    /**
     * {@inheritdoc}
     */
    public function switchToWindow($name = null)
    {
        $this->client->switchTo()->window($name);
        $this->client->refreshCrawler();
    }

    /**
     * {@inheritdoc}
     */
    public function switchToIFrame($name = null)
    {
        if (null === $name) {
            $this->client->switchTo()->defaultContent();
        } else {
            $this->client->switchTo()->frame($name);
        }
        $this->client->refreshCrawler();
    }

    /**
     * {@inheritdoc}
     */
    public function setCookie($name, $value = null)
    {
        if (null === $value) {
            $this->deleteCookie($name);

            return;
        }

        $jar = $this->client->getCookieJar();
        // @see: https://github.com/w3c/webdriver/issues/1238
        $jar->set(new Cookie($name, \rawurlencode((string)$value)));
    }

    /**
     * Deletes a cookie by name.
     *
     * @param string $name Cookie name.
     */
    private function deleteCookie($name)
    {
        $path = $this->getCookiePath();
        $jar = $this->client->getCookieJar();

        do {
            if (null !== $jar->get($name, $path)) {
                $jar->expire($name, $path);
            }

            $path = preg_replace('/.$/', '', $path);
        } while ($path);
    }

    /**
     * Returns current cookie path.
     *
     * @return string
     */
    private function getCookiePath()
    {
        $path = dirname(parse_url($this->getCurrentUrl(), PHP_URL_PATH));

        if ('\\' === DIRECTORY_SEPARATOR) {
            $path = str_replace('\\', '/', $path);
        }

        return $path;
    }

    /**
     * {@inheritdoc}
     */
    public function getCookie($name)
    {
        $cookies = $this->client->getCookieJar()->all();

        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $name) {
                return \urldecode($cookie->getValue());
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getContent()
    {
        return $this->client->getWebDriver()->getPageSource();
    }

    /**
     * {@inheritdoc}
     */
    public function getScreenshot($saveAs = null): string
    {
        return $this->client->takeScreenshot($saveAs);
    }

    /**
     * {@inheritdoc}
     */
    public function getWindowNames()
    {
        return $this->client->getWindowHandles();
    }

    /**
     * {@inheritdoc}
     */
    public function getWindowName()
    {
        return $this->client->getWindowHandle();
    }

    /**
     * {@inheritdoc}
     */
    public function isVisible($xpath)
    {
        return $this->getCrawlerElement($this->getFilteredCrawler($xpath))->isDisplayed();
    }

    /**
     * {@inheritdoc}.
     */
    public function mouseOver($xpath)
    {
        $this->client->getMouse()->mouseMove($this->toCoordinates($xpath));
    }

    /**
     * {@inheritdoc}
     */
    public function focus($xpath)
    {
        $jsNode = $this->getJsNode($xpath);
        $script = \sprintf('%s.focus()', $jsNode);
        $this->executeScript($script);
    }

    /**
     * {@inheritdoc}
     */
    public function blur($xpath)
    {
        $jsNode = $this->getJsNode($xpath);
        $script = \sprintf('%s.blur();', $jsNode);
        // ensure element had active state; just for passing EventsTest::testBlur
        if ($this->evaluateScript(\sprintf('document.activeElement !== %s', $jsNode))) {
            $script = \sprintf('%s.focus();%s', $jsNode, $script);
        }
        $this->executeScript($script);
    }

    /**
     * {@inheritdoc}
     */
    public function keyPress($xpath, $char, $modifier = null)
    {
        $webDriverActions = $this->getWebDriverActions();
        $element = $this->getCrawlerElement($this->getFilteredCrawler($xpath));
        $key = $this->geWebDriverKeyValue($char, $modifier);
        $webDriverActions->sendKeys($element, $key.WebDriverKeys::NULL)->perform();
    }

    /**
     * {@inheritdoc}
     */
    public function keyDown($xpath, $char, $modifier = null)
    {
        $webDriverActions = $this->getWebDriverActions();
        $element = $this->getCrawlerElement($this->getFilteredCrawler($xpath));
        $key = $this->geWebDriverKeyValue($char, $modifier);
        $webDriverActions->keyDown($element, $key.WebDriverKeys::NULL)->perform();
    }

    /**
     * {@inheritdoc}
     */
    public function keyUp($xpath, $char, $modifier = null)
    {
        $webDriverActions = $this->getWebDriverActions();
        $element = $this->getCrawlerElement($this->getFilteredCrawler($xpath));
        $key = $this->geWebDriverKeyValue($char, $modifier);
        $webDriverActions->keyUp($element, $key)->perform();
    }

    /**
     * {@inheritdoc}
     */
    public function isSelected($xpath)
    {
        return $this->getCrawlerElement($this->getFilteredCrawler($xpath))->isSelected();
    }

    /**
     * {@inheritdoc}
     */
    public function findElementXpaths($xpath)
    {
        $nodes = $this->getCrawler()->filterXPath($xpath);

        $elements = array();
        foreach ($nodes as $i => $node) {
            $elements[] = sprintf('(%s)[%d]', $xpath, $i + 1);
        }

        return $elements;
    }

    /**
     * {@inheritdoc}
     */
    public function getTagName($xpath)
    {
        return $this->getCrawlerElement($this->getFilteredCrawler($xpath))->getTagName();
    }

    /**
     * {@inheritdoc}
     */
    public function getText($xpath)
    {
        $text = $this->getFilteredCrawler($xpath)->text();
        $text = str_replace("\n", ' ', $text);
        $text = preg_replace('/ {2,}/', ' ', $text);

        return trim($text);
    }

    /**
     * {@inheritdoc}
     */
    public function getHtml($xpath)
    {
        // cut the tag itself (making innerHTML out of outerHTML)
        return preg_replace('/^\<[^\>]+\>|\<[^\>]+\>$/', '', $this->getOuterHtml($xpath));
    }

    /**
     * {@inheritdoc}
     */
    public function getOuterHtml($xpath)
    {
        $crawler = $this->getFilteredCrawler($xpath);

        return $crawler->html();
    }

    /**
     * {@inheritdoc}
     */
    public function getAttribute($xpath, $name)
    {
        $crawler = $this->getFilteredCrawler($xpath);

        $attribute = $this->getCrawlerElement($crawler)->getAttribute($name);

        // let's get hacky
        if ('' === $attribute) {
            $html = \strtolower($crawler->html());
            $name = \strtolower($name).'=';
            if (0 === \substr_count($html, $name)) {
                $attribute = null;
            }
        }

        return $attribute;
    }

    /**
     * {@inheritdoc}
     */
    public function getValue($xpath)
    {
        try {
            $formField = $this->getFormField($xpath);
            $value = $formField->getValue();
            if ('' === $value && $formField instanceof ChoiceFormField) {
                $value = null;
            }
        } catch (DriverException $e) {
            // e.g. element is an option
            $element = $this->getCrawlerElement($this->getFilteredCrawler($xpath));
            $value = $element->getAttribute('value');
        }

         return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function setValue($xpath, $value)
    {
        $element = $this->getCrawlerElement($this->getFilteredCrawler($xpath));
        $jsNode = $this->getJsNode($xpath);

        if ('input' === $element->getTagName() && \in_array($element->getAttribute('type'), ['date', 'color'])) {
            $this->executeScript(\sprintf('%s.value = \'%s\'', $jsNode, $value));
        } else {
            try {
                $formField = $this->getFormField($xpath);
                $formField->setValue($value);
            } catch (DriverException $e) {
                // e.g. element is on option
                $element->sendKeys($value);
            }
        }

        // Remove the focus from the element if the field still has focus in
        // order to trigger the change event. By doing this instead of simply
        // triggering the change event for the given xpath we ensure that the
        // change event will not be triggered twice for the same element if it
        // has lost focus in the meanwhile. If the element has lost focus
        // already then there is nothing to do as this will already have caused
        // the triggering of the change event for that element.
        if ($this->evaluateScript(\sprintf('document.activeElement === %s', $jsNode))) {
            $this->executeScript('document.activeElement.blur();');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function check($xpath)
    {
        $this->getChoiceFormField($xpath)->tick();
    }

    /**
     * {@inheritdoc}
     */
    public function uncheck($xpath)
    {
        $this->getChoiceFormField($xpath)->untick();
    }

    /**
     * {@inheritdoc}
     */
    public function selectOption($xpath, $value, $multiple = false)
    {
        $field = $this->getFormField($xpath);

        if (!$field instanceof ChoiceFormField) {
            throw new DriverException(
                sprintf('Impossible to select an option on the element with XPath "%s" as it is not a select or radio input', $xpath)
            );
        }

        $field->select($value);
    }

    /**
     * {@inheritdoc}
     */
    public function click($xpath)
    {
        $this->client->getMouse()->click($this->toCoordinates($xpath));
        $this->client->refreshCrawler();
    }

    /**
     * {@inheritdoc}
     */
    public function doubleClick($xpath)
    {
        $this->client->getMouse()->doubleClick($this->toCoordinates($xpath));
    }

    /**
     * {@inheritdoc}
     */
    public function rightClick($xpath)
    {
        $this->client->getMouse()->contextClick($this->toCoordinates($xpath));
    }

    /**
     * {@inheritdoc}
     */
    public function isChecked($xpath)
    {
        return $this->getChoiceFormField($xpath)->hasValue();
    }

    /**
     * {@inheritdoc}
     */
    public function attachFile($xpath, $path)
    {
        $field = $this->getFormField($xpath);

        if (!$field instanceof FileFormField) {
            throw new DriverException(
                sprintf('Impossible to attach a file on the element with XPath "%s" as it is not a file input', $xpath)
            );
        }

        $field->upload($path);
    }

    /**
     * {@inheritdoc}
     */
    public function dragTo($sourceXpath, $destinationXpath)
    {
        $webDriverActions = $this->getWebDriverActions();
        $source = $this->getCrawlerElement($this->getFilteredCrawler($sourceXpath));
        $target = $this->getCrawlerElement($this->getFilteredCrawler($destinationXpath));
        $webDriverActions->dragAndDrop($source, $target)->perform();
    }

    /**
     * {@inheritdoc}
     */
    public function executeScript($script)
    {
        if (\preg_match('/^function[\s\(]/', $script)) {
            $script = \preg_replace('/;$/', '', $script);
            $script = '(' . $script . ')';
        }

        return $this->client->executeScript($script);
    }

    /**
     * {@inheritdoc}
     */
    public function evaluateScript($script)
    {
        if (0 !== \strpos(\trim($script), 'return ')) {
            $script = 'return ' . $script;
        }

        return $this->client->executeScript($script);
    }

    /**
     * {@inheritdoc}
     */
    public function wait($timeout, $condition)
    {
        $script = "return $condition;";
        $start = microtime(true);
        $end = $start + $timeout / 1000.0;

        do {
            $result = $this->evaluateScript($script);
            \usleep(100000);
        } while (\microtime(true) < $end && !$result);

        return (bool) $result;
    }

    /**
     * {@inheritdoc}
     */
    public function resizeWindow($width, $height, $name = null)
    {
        $size = new WebDriverDimension($width, $height);
        $this->client->getWebDriver()->manage()->window()->setSize($size);
    }

    /**
     * {@inheritdoc}
     */
    public function maximizeWindow($name = null)
    {
        $width = $this->evaluateScript('screen.width');
        $height = $this->evaluateScript('screen.height');
        $this->resizeWindow($width, $height, $name);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm($xpath)
    {
        $crawler = $this->getFilteredCrawler($xpath);

        $this->client->submit($crawler->form());
        $this->client->refreshCrawler();
    }

    /**
     * @return Response
     *
     * @throws DriverException If there is not response yet
     */
    protected function getResponse()
    {
        $response = $this->client->getInternalResponse();

        if (null === $response) {
            throw new DriverException('Unable to access the response before visiting a page');
        }

        return $response;
    }

    /**
     * Prepares URL for visiting.
     * Removes "*.php/" from urls and then passes it to BrowserKitDriver::visit().
     *
     * @param string $url
     *
     * @return string
     */
    protected function prepareUrl($url)
    {
        $replacement = ($this->removeHostFromUrl ? '' : '$1').($this->removeScriptFromUrl ? '' : '$2');

        return preg_replace('#(https?\://[^/]+)(/[^/\.]+\.php)?#', $replacement, $url);
    }

    /**
     * Returns form field from XPath query.
     *
     * @param string $xpath
     *
     * @return FormField
     *
     * @throws DriverException
     */
    private function getFormField($xpath)
    {
        try {
            $formField = $this->getChoiceFormField($xpath);
        } catch (DriverException $e) {
            $formField = null;
        }
        if (!$formField) {
            try {
                $formField = $this->getInputFormField($xpath);
            } catch (DriverException $e) {
                $formField = null;
            }
        }
        if (!$formField) {
            try {
                $formField = $this->getFileFormField($xpath);
            } catch (DriverException $e) {
                $formField = null;
            }
        }
        if (!$formField) {
            $formField = $this->getTextareaFormField($xpath);
        }

        return $formField;
    }

    /**
     * Returns the checkbox field from xpath query, ensuring it is valid.
     *
     * @param string $xpath
     *
     * @return ChoiceFormField
     *
     * @throws DriverException when the field is not a checkbox
     */
    private function getChoiceFormField($xpath)
    {
        $element = $this->getCrawlerElement($this->getFilteredCrawler($xpath));
        try {
            $choiceFormField = new ChoiceFormField($element);
        } catch (\LogicException $e) {
            throw new DriverException(
                sprintf(
                    'Impossible to get the element with XPath "%s" as it is not a choice form field. %s',
                    $xpath,
                    $e->getMessage()
                )
            );
        }

        return $choiceFormField;
    }

    /**
     * Returns the input field from xpath query, ensuring it is valid.
     *
     * @param string $xpath
     *
     * @return InputFormField
     *
     * @throws DriverException when the field is not a checkbox
     */
    private function getInputFormField($xpath)
    {
        $element = $this->getCrawlerElement($this->getFilteredCrawler($xpath));
        try {
            $inputFormField = new InputFormField($element);
        } catch (\LogicException $e) {
            throw new DriverException(sprintf('Impossible to check the element with XPath "%s" as it is not an input form field.', $xpath));
        }

        return $inputFormField;
    }

    /**
     * Returns the input field from xpath query, ensuring it is valid.
     *
     * @param string $xpath
     *
     * @return FileFormField
     *
     * @throws DriverException when the field is not a checkbox
     */
    private function getFileFormField($xpath)
    {
        $element = $this->getCrawlerElement($this->getFilteredCrawler($xpath));
        try {
            $fileFormField = new FileFormField($element);
        } catch (\LogicException $e) {
            throw new DriverException(sprintf('Impossible to check the element with XPath "%s" as it is not a file form field.', $xpath));
        }

        return $fileFormField;
    }

    /**
     * Returns the textarea field from xpath query, ensuring it is valid.
     *
     * @param string $xpath
     *
     * @return TextareaFormField
     *
     * @throws DriverException when the field is not a checkbox
     */
    private function getTextareaFormField($xpath)
    {
        $element = $this->getCrawlerElement($this->getFilteredCrawler($xpath));
        try {
            $textareaFormField = new TextareaFormField($element);
        } catch (\LogicException $e) {
            throw new DriverException(sprintf('Impossible to check the element with XPath "%s" as it is not a textarea.', $xpath));
        }

        return $textareaFormField;
    }

    /**
     * Returns WebDriverElement from crawler instance.
     *
     * @param Crawler $crawler
     *
     * @return WebDriverElement
     *
     * @throws DriverException when the node does not exist
     */
    private function getCrawlerElement(Crawler $crawler): WebDriverElement
    {
        $node = $crawler->getElement(0);

        if (null !== $node) {
            return $node;
        }

        throw new DriverException('The element does not exist');
    }

    /**
     * Returns a crawler filtered for the given XPath, requiring at least 1 result.
     *
     * @param string $xpath
     *
     * @return Crawler
     *
     * @throws DriverException when no matching elements are found
     */
    private function getFilteredCrawler($xpath): Crawler
    {
        if (!count($crawler = $this->getCrawler()->filterXPath($xpath))) {
            throw new DriverException(sprintf('There is no element matching XPath "%s"', $xpath));
        }

        return $crawler;
    }

    /**
     * Returns crawler instance (got from client).
     *
     * @return Crawler
     *
     * @throws DriverException
     */
    private function getCrawler(): Crawler
    {
        $crawler = $this->client->getCrawler();

        if (null === $crawler) {
            throw new DriverException('Unable to access the response content before visiting a page');
        }

        return $crawler;
    }

    private function getJsNode(string $xpath): string
    {
        return sprintf('document.evaluate(`%s`, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue', $xpath);
    }

    private function toCoordinates(string $xpath): WebDriverCoordinates
    {
        $element = $this->getCrawlerElement($this->getFilteredCrawler($xpath));

        if (!$element instanceof WebDriverLocatable) {
            throw new \RuntimeException(
                sprintf('The element of "%s" xpath selector does not implement "%s".', $xpath, WebDriverLocatable::class)
            );
        }

        return $element->getCoordinates();
    }

    private function getWebDriverActions(): WebDriverActions
    {
        $webDriver = $this->client->getWebDriver();
        if (!$webDriver instanceof WebDriverHasInputDevices) {
            throw new UnsupportedDriverActionException('Mouse manipulations are not supported by %s', $this);
        }
        $webDriverActions = new WebDriverActions($webDriver);

        return $webDriverActions;
    }

    private function geWebDriverKeyValue($char, $modifier = null)
    {
        if (\is_int($char)) {
            $char = \strtolower(\chr($char));
        }

        switch ($modifier) {
            case 'alt':
                return WebDriverKeys::ALT.$char;
            case 'ctrl':
                return WebDriverKeys::CONTROL.$char;
            case 'shift':
                return WebDriverKeys::SHIFT.$char;
            case 'meta':
                return WebDriverKeys::META.$char;
            case null:
            default:
                return $char;
        }
    }
}
