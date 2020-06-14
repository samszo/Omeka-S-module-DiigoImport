<?php
namespace DiigoImport\Diigo;

class Url
{
    /**
     * Diigo API base URI.
     */
	const URI = 'https://secure.diigo.com';
	const API = '/api/v2/bookmarks';
    const URIOUT = 'https://www.diigo.com/interact_api/load_user_items';
    const URIWHAT = 'https://www.diigo.com/interact_api/search_user_items';
    

    /**
     * @var string
     */
    protected $user;
    /**
     * @var string
     */
    protected $key;

    /**
     * Construct a Diigo URL.
     *
     * @param string $key The Diigo API key
     * @param string $user The Diigo user ID
     */
    public function __construct($key, $user)
    {
        $this->user = $user;
        $this->key = $key;
    }

    /**
     * The set of all items in the library
     *
     * @param array $params
     * @return string
     */
    public function items(array $params = [])
    {
        return sprintf('%s%s?%s', self::URI, self::API, $this->getParams($params));
    }

    /**
     * The set of all items in the library via outliner
     *
     * @param array $params
     * @return string
     */
    public function itemsOutliner(array $params = [])
    {        
        return sprintf('%s?%s', self::URIOUT, $this->getParams($params));
    }

    /**
     * The set of items in the library via outliner corresponding to a what query
     *
     * @param array $params
     * @return string
     */
    public function itemsOutlinerWhat(array $params = [])
    {        
        return sprintf('%s?%s', self::URIWHAT, $this->getParams($params));
    }


    /**
     * The URL to an item file.
     *
     * @param string $itemKey
     * @param array $params
     * @return string
     */
    public function itemFile($itemKey, array $params = [])
    {
        return sprintf('%s/%s/%s/items/%s/file%s', self::BASE, $this->type,
            $this->id, $itemKey, $this->getParams($params));
    }


    /**
     * Build and return a URL query string
     *
     * @param array $params
     * @return string
     */
    public function getParams(array $params)
    {
        $p = 'key='.$this->key.'&user='.$this->user;
        if (empty($params)) {
            return $p;
        }
        $params['key']=$this->key;
        $params['user']=$this->user;
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
