<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace Noirapi\Lib\Tracy;

use Override;
use Tracy\IBarPanel;

use function count;
use function in_array;
use function is_array;
use function is_object;
use function is_string;

/** @psalm-suppress UnusedClass */
class SessionPanel implements IBarPanel
{
    /**
     * Base64 icon for the Tracy panel.
     * @var string
     * @see https://www.flaticon.com/free-icons/session
     * @author Freepik.com
     * @license http://file000.flaticon.com/downloads/license/license.pdf
     * @noinspection SpellCheckingInspection
     */
    // phpcs:ignore
    public string $icon = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAAdgAAAHYBTnsmCAAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAEmSURBVDiNpdLNKoRxFAbw35ghIhYaST4ixQ0oZeMGFGsbSik7IlkoCytNcgesZO8epNwAu1GmlBJRPhofi/eIXu+IPPWv0/885znnOR3+iVyN/1bMoA31uMIe7tLEuoziHmzhBR1oRxWb6K41QR6jaMJUdLzBfuSn0YhOHOIBx3jJB2EBE5iMRBXbGEERBxjDPeYwgC6cfFgooow1PKMQ/5cY/zLlU3DKUfNtB+doCP9wio2IqyFc/lqQFriQLOoVi2iJtxTFXaj8JAAl9Iffdcn2H0O4lCZnCVSwiuawcie5hZV0dz6XlcY9dmrkagoUo/NvUJTcijyW0Se1XQziOiOGW/RiuCDxOp/RZRZDER9hN4OzkZOc7hDefjn+B3I4+2PNd7wDWvk5jBRsprIAAAAASUVORK5CYII=';

    public string $title = 'Session Panel';
    public string $title_attributes = 'style="font-size:1.6em"';
    public string $key_attributes = 'style="font-weight:bold;color:#333;font-family:Courier New;font-size:1.1em"';
    public string $value_attributes = 'style="font-weight:bold;color:#555;font-family:Courier New;font-size:1.1em"';
    // phpcs:ignore
    public string $value_mod_attributes = 'style="font-weight:bold;color:#A52A2A;font-family:Courier New;font-size:1.1em"';

    public array $SESSION;
    public int $status;

    /**
     * @psalm-mutation-free
     */
    public function __construct(array $SESSION, int $status)
    {
        $this->SESSION = $SESSION;
        $this->status = $status;
    }

    /**
     * @return string
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getTab(): string
    {

        $html = "<img src=\"$this->icon\" alt=\"$this->title\" />&nbsp";
        if ($this->status !== PHP_SESSION_ACTIVE) {
            $html .= $this->title . ' session not active';
        } else {
            $html .= $this->title . ' (' . count($this->SESSION) . ')';
        }

        return $html;
    }

    /**
     * @return string
     */
    #[Override]
    public function getPanel(): string
    {

        $html = '<h1 ' . $this->title_attributes . '>' . $this->title . '</h1>';
        $html .= '<div class="tracy-inner tracy-InfoPanel" style="width: 500px;">';
        $html .= $this->cell($this->SESSION);
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array|object $data
     * @return string
     * @noinspection DuplicatedCode
     */
    private function cell(array|object $data): string
    {

        $hidden = [
            'password',
            'key',
            'token',
            'salt',
            'pass',
        ];

        $return = '<table class="tracy-sortable">';

        foreach ($data as $key => $value) {
            if (is_string($value) && in_array($key, $hidden, true)) {
                $value = "<span $this->value_mod_attributes>" . substr($value, 0, 3) . '...' . substr($value, -3) . '</span>'; //phpcs:ignore
            } elseif ($value === true) {
                $value = "<span $this->value_mod_attributes>true</span>";
            } elseif ($value === false) {
                $value = "<span $this->value_mod_attributes>false</span>";
            } elseif ($value === null) {
                $value = "<span $this->value_mod_attributes>null</span>";
            }

            $return .= "<tr><td><span $this->key_attributes>$key</span></td><td $this->value_attributes>";

            if (is_array($value) || is_object($value)) {
                $return .= $this->cell($value);
            } else {
                $return .= $value;
            }

            $return .= '</td><tr>';
        }

        $return .= '</tr></table>';

        return $return;
    }
}
