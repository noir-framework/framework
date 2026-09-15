<?php

declare(strict_types=1);

namespace Noirapi\Lib\Tracy;

use Noirapi\Lib\View;
use Override;
use Tracy\IBarPanel;

/** @psalm-api */
class SystemBarPanel implements IBarPanel
{
    /**
     * Base64 icon for the Tracy panel.
     * @var string
     * @see https://www.flaticon.com/free-icons/barrier
     * @author Freepik.com
     * @license http://file000.flaticon.com/downloads/license/license.pdf
     * @noinspection SpellCheckingInspection
     */
    // phpcs:ignore
    public string $icon = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAAAbwAAAG8B8aLcQwAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAACiSURBVDiN7dI9aoJhEATgZ1+rVEHQLs1XfTYewNKcwltYpEvlFTxD7iEIEm9hZZUqZQgJa+EK9jYWLgwMw+zA/kRmuqXaTd2PgHPVFSaZeeE9WvERRsUb+ivf5HLBFb7xjgUO+MAc28K8tEN5lljjreEPz/jFER2G2GNa2JfWladhh0GU4R+BL7zgKTM/I+K1xtxExAw/FTCuDQzi8Yl3EHACaEg66Cf0iAkAAAAASUVORK5CYII=';
    public string $title = 'System Panel';

    private View $view;

    /**
     * @psalm-mutation-free
     */
    public function __construct(View $view)
    {

        $this->view = $view;
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
        /** @psalm-suppress  PossiblyNullArgument */
        // phpcs:ignore
        $template = is_string($this->view->getTemplate()) ? substr($this->view->getTemplate(), (int)strpos($this->view->getTemplate(), '/app')) : 'None';
        $called = $this->view->request->controller . '::' . $this->view->request->function;
        if (isset($this->view->getResponse()->initiator_line)) {
            // phpcs:ignore
            $called .= '::<strong>' . (is_int($this->view->getResponse()->initiator_line) ? (string)$this->view->getResponse()->initiator_line : '(none)') . '</strong>';
        }
        // phpcs:ignore
        $html .= $this->view->request->method . '[' . $called . '][' . basename($this->view->getLayout() ?? 'None') . '][' . $template . ']';

        return $html;
    }

    /**
     * @return string
     *
     * @psalm-pure
     */
    #[Override]
    public function getPanel(): string
    {

        return '';
    }
}
