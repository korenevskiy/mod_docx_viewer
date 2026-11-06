<?php
namespace Joomla\Module\DocxViewer\Dispatcher;

defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Helper\HelperFactoryAwareInterface;
use Joomla\CMS\Helper\HelperFactoryAwareTrait;
use Joomla\CMS\Uri\Uri;

class Dispatcher extends AbstractModuleDispatcher implements HelperFactoryAwareInterface
{
    use HelperFactoryAwareTrait;

    public function dispatch(): void
    {
        $input = $this->app->getInput();

        if ($input->getCmd('docx_action') === '1') {
            try {
                $helper = $this->getHelperFactory()->getHelper('DocxViewerHelper');
                $data = $helper->handleAjax($this->app);

                $payload = [
                    'success' => true,
                    'message' => '',
                    'data'    => $data,
                ];
            } catch (\Throwable $e) {
                $payload = [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data'    => null,
                ];
            }

            $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->app->close();

            return;
        }

        parent::dispatch();
    }

    protected function getLayoutData(): array
    {
        $data = parent::getLayoutData();

        try {
            $uri = clone Uri::getInstance();
            $uri->setVar('docx_action', '1');
            $data['ajaxUrl'] = $uri->toString();
        } catch (\Throwable $e) {
            $data['ajaxUrl'] = Uri::base() . 'index.php?docx_action=1';
        }

        return $data;
    }
}