<?php

declare(strict_types=1);

namespace Noirapi\Lib\Tracy;

use Noirapi\Helpers\Curl;
use Override;
use Tracy\Debugger;
use Tracy\IBarPanel;

use function count;
use function gettype;
use function is_array;
use function is_object;

/**
 * @codeCoverageIgnore
 */
class CurlBarPanel implements IBarPanel
{
    /**
     * Base64 icon for the Tracy panel.
     * @var string
     * @see https://www.flaticon.com/free-icon/http-search-symbol_37387
     * @author Freepik.com
     * @license http://file000.flaticon.com/downloads/license/license.pdf
     * @noinspection SpellCheckingInspection
     */
    public string $icon = ' data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAAAUCAYAAAA9djs/AAAABHNCSVQICAgIfAhkiAAAAAFzUkdCAK7OHOkAAAAEZ0FNQQAAsY8L/GEFAAAACXBIWXMAABJ0AAASdAHeZh94AAAAGXRFWHRTb2Z0d2FyZQB3d3cuaW5rc2NhcGUub3Jnm+48GgAAA0JJREFUWEftWE0odFEYfmYMSpGiRMpCyN8GZcnsJln6KYUFSmQootgqyUosRlhQkhQlIUkpG0QWQn5iCGU18hfifOd955jmzs9nhHzTfE89c+/7nHPv3Pvc97zn3Kubn58XCwsLCESYTCboQkJCxPPzs5ICC/LeoZNbYQ8DEz9iQE5ODnp7e6HX65Xyb+Dm5gYVFRW4vr5WygcG0I3k5eUhOjoa29vbmJmZwePjo2r1jvHxcRQVFWFnZ0cpvw9K97S0NDQ1NfHDcQYZoGFQUJDo7+8Xrjg4OBBZWVlu/V0pDRBXV1ce236LCQkJfA+NjY0a3SB/3NDa2or6+nrMzs6yW3t7e6iqqoLZbMbU1BQyMzNxe3uren8MyqLk5GQMDg5yXFtbyxmVlJSEuLg41giUopeXl0hNTVWKHSMjIyguLkZYWBi2trawurrqUyb6Co0j9PTv7++F1Wrlfec2co8gzdHornTNgK6uLiFNdMQnJyeira1NdHd3i6WlJT7nysqKGBgYEM3NzUIONdY2NjbE9PS0kAZyLA0Uu7u7rL2fy1d6ywBJTSAyMjK4Y3t7u0YnRkZGCum8GB0ddWtzpicDjo6O+CKI5+fnbAC1paSk8P/l5uY6+oeGhrJWWlrK8bsBMTExory8XDw8PIjg4GBHf1/ozQC3Mn13d8fb2NhY3jojKioK8uI0VdRXJCYm4vT0lBkfH6/Uz6Gvrw89PT0YGxvDy8uLUr8GNwPoAi8uLlBZWYmIiAil2kFjV6fTYXFxUSm+4/DwEDKDmGdnZ0r9HKhu5Ofno6amRilfh8eJuq6uDuHh4ZBVH52dnSgrK8Py8jJaWlq4vbCwkDPBG8gkV7y+vnKRI769vSn1cxgeHsb+/r6KvgceDaD5vqSkBAaDAR0dHZxyRqMRk5OTzIaGBqyvryM9PV0doYUcamrPjrW1NUxMTKgIfD56mgSbzQaLxaIZVmQWacfHxxzTzEDxd1Z+Z2iKgjOpGGVnZ4uCggIuIu96dXU1zxRUEGWmaI4h+tM64K9r1aenJ2xubmJubg5yWlQqMDQ0xKtEOW1BTpVK9U/8yLuAPy2F/78MSX67Af4EPaVGoII/iAT2JzET/gCQH4O4BL0/eQAAAABJRU5ErkJggg=='; // phpcs:ignore

    public string $title = 'Curl logger';
    public string $title_attributes = 'style="font-size:1.6em"';
    public string $time_attributes = 'style="font-weight:bold;color:#333;font-family:Courier New;font-size:1.1em"';

    /**
     * Get total queries execution time
     *
     * @return string
     *
     * @psalm-external-mutation-free
     */
    protected function getTotalTime(): string
    {
        return (string) round(array_sum(array_column(Curl::getLog(), 'time')), 4);
    }

    /**
     * @return string
     *
     * @psalm-external-mutation-free
     */
    #[Override]
    public function getTab(): string
    {

        $html = '<img src="' . $this->icon . '" alt="Curl Request logger" /> ';
        $queries = count(Curl::getLog());

        if ($queries === 0) {
            $html .= 'no requests!';

            return $html;
        }

        if ($queries === 1) {
            $html .= '1 request';
        } else {
            $html .= $queries . ' requests';
        }

        $html .= ' / ' . $this->getTotalTime() . ' ms';

        return $html;
    }

    /**
     * Renders HTML code for custom panel.
     *
     * @return string
     *
     * @psalm-external-mutation-free
     */
    #[Override]
    public function getPanel(): string
    {

        $html = '<h1 ' . $this->title_attributes . '>' . $this->title . '</h1>';

        $html .= '<div class="tracy-inner tracy-InfoPanel">';
        if (count(Curl::getLog()) > 0) {
            $html .= '<table class="tracy-sortable">';
            $html .= '<tr>';
            $html .= '<th>Time(ms)</td>';
            $html .= '<th>Statement</td>';
            $html .= '</tr>';
            foreach (Curl::getLog() as $request) {
                $html .= '<tr>';
                /** @psalm-suppress InvalidOperand */
                $html .= '<td><span ' . $this->time_attributes . '>' . round($request['time'], 4) . '</span></td>';
                $html .= '<td><i class="fa fa-chevron-right"></i> ' . $request['url'] . ' <i class="fa fa-chevron-left"></i> ' . $request['info'] . '</td>'; //phpcs:ignore
                $html .= '</tr>';

                if (! empty($request['request'])) {
                    $html .= '<tr>';
                    $html .= '<td>';
                    $html .= gettype($request['request']);
                    $html .= '</td>';
                    $html .= '<td>';
                    $html .= '<a href="#" class="tracy-curl-click">Request...</a>' . PHP_EOL;
                    $html .= '<pre id="tracy-curl-request-element" style="display:none">';
                    if (is_array($request['request'])) {
                        $html .= print_r($request[ 'request' ], true);
                    } else {
                        $html .= $request['request'];
                    }
                    $html .= '</pre>';
                    $html .= '</td>';
                    $html .= '</tr>';
                }

                $html .= '<tr>';
                $html .= '<td>';
                $html .= gettype($request['response']);
                $html .= '</td>';
                $html .= '<td>';
                $html .= '<a href="#" class="tracy-curl-click">Response...</a>' . PHP_EOL;
                $html .= '<pre id="tracy-curl-response-element" style="display:none">';
                if (is_object($request['response']) || is_array($request['response'])) {
                    $html .= print_r($request[ 'response' ], true);
                } else {
                    $html .= $request['response'];
                }
                $html .= '</pre>';
                $html .= '</td>';
                $html .= '</tr>';
            }
            $html .= '</table>';
        } else {
            $html .= '<p style="font-size:1.2em;font-weight:bold;padding:10px">No curl requests were executed!</p>';
        }
        $html .= '</div>';

        // Works only with a custom Tracy version
        /** @noinspection PhpUndefinedFieldInspection */
        if (isset(Debugger::$nonce)) { // @phpstan-ignore-line
            /** @noinspection PhpUndefinedFieldInspection */
            $nonce = 'nonce-' . Debugger::$nonce;
        } else {
            $nonce = '';
        }

        $html .= <<<EOT
<script $nonce>

    const tracyCurlCallBack = document.querySelectorAll('.tracy-curl-click');

    const tracyCurlCallBackList = [...tracyCurlCallBack].map(el => {
        el.addEventListener("click", function(event) {
            let element = event.currentTarget.nextSibling.nextSibling;
            toggle(element);
	    },false);
    })

    function toggle(element) {
        if (element.style.display === 'block') {
            element.style.display = 'none';
        } else {
            element.style.display = 'block';
        }
    }

</script>
EOT;

        return $html;
    }
}
