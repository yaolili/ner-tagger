<?php 
header('Access-Control-Allow-Origin: *');
header('content-type: application/json; charset=utf-8');

if(isset($_GET["q"]))
{
	$query = urldecode($_GET["q"]);
}
else
{
	echo "need parameter q!";
	return;
}

if(isset($_GET["source"]))
{
	if($_GET["source"] == "news")
	{	
		$query = urlencode($query);

		//get google news content
		$url = "https://ajax.googleapis.com/ajax/services/search/news?v=1.0&q=$query"."&rsz=8&ned=cn";

		$news = json_decode(curlGetInfo($url));
		$data = $news->responseData->results;
		$sentence = "";
		for($i = 0; $i < count($data); $i++)
		{
			//regard "endend" as a flag;
			$sentence .= $data[$i]->content . " endend " ;
			$title[] = $data[$i]->title;
		}
		
		//do some preProcess
		$sentence = preProcess($sentence);
		 
		//use scws to segment
		$sentence = scwsSeg($sentence);
		
		$entity = nerTagger($sentence);
		//var_dump($entity);
		
		$entity = noDuplicated($entity, 1);	
		for($i = 0; $i < count($entity); $i++)
		{
			$entity[$i]["time"] = $data[$i]->publishedDate;
			$entity[$i]["title"] = $title[$i];
			$entity[$i]["content"] = $data[$i]->content;
			
		}
		$entity = noEventInfo($entity); 
		//var_dump($entity);
	}
	else
	{
		//$_GET["source"] == "twitter"
		require_once("TwitterAPIExchange.php");
		$query = urlencode($query);
		$settings = array(
				'oauth_access_token' => "yourToken",
				'oauth_access_token_secret' => "yourTokenSecret",
				'consumer_key' => "yourConsumerKey",
				'consumer_secret' => "yourConsumerSecret"
			);
			
		$url = 'https://api.twitter.com/1.1/search/tweets.json';
		$getfield = "?q=$query&lang=zh-cn";

		$requestMethod = 'GET';
		$twitter = new TwitterAPIExchange($settings);
		$response = $twitter->setGetfield($getfield)
			->buildOauth($url, $requestMethod)
			->performRequest();	
		//$twitter = $response->statuses;
		$response = json_decode($response);
		$statuses = $response->statuses;
		$text = "";
		for($i = 0; $i < count($statuses); $i++)
		{
			$text .= $statuses[$i]->text;
		}

		$text = preProcess($text);
		$text = scwsSeg($text);
		$entity = nerTagger($text);
		//var_dump($entity);
		$entity = noDuplicated($entity, 0);
	}
}
else
{
	echo "need parameter source!";
}


function preProcess($text)
{
	//remove html tags;
	$sentence = strip_tags($text);
	
	//remove English & Chinese punct
	$sentence = preg_replace("/[[:punct:]\s]/",' ',$sentence);
		
	$sentence = urlencode($sentence);
	//notice: cannot break into several lines, keep it in one line!
	$sentence = preg_replace("/(%EF%BC%8C|%E3%80%82|%E2%80%9D|%E2%80%9C|%EF%BC%9B|%E3%80%90|%E3%80%91|%EF%BC%9F|%E3%80%8A|%E3%80%8B|%EF%BC%88|%EF%BC%89|%E3%80%81)/",' ',$sentence);
	$sentence = urldecode($sentence);
	return $sentence;
}

function scwsSeg($text)
{
	
	$so = scws_new();
	$so->set_charset('utf8');
	$so->send_text($text);
	$sentence = "";
	while ($segmentation = $so->get_result())
	{
		for($i = 0; $i < count($segmentation); $i++)
		{
			$sentence .= " ".$segmentation[$i]["word"];		
		}
	}
	$so->close();
	return $sentence;
}

function curlGetInfo($url)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_USERAGENT, 
		'Mozilla/5.0 (compatible; MSIE 5.01; Windows NT 5.0)');
	curl_setopt($ch, CURLOPT_TIMEOUT, 15);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	if ($ch === FALSE){
		throw new Exception('cURL not supported');             
	}

	$content = curl_exec($ch);
	if ($content === FALSE) {
		throw new Exception('cURL error: '.curl_error($ch));
	}
	curl_close($ch);
	return $content;
}

function  nerTagger($sentence)
{
	//using stanford ner tagger
	require 'autoload.php';

	$pos = new \StanfordNLP\NERTagger(
		'/your path to/ner/stanford-ner-2013-11-12/classifiers/chinese.misc.distsim.crf.ser.gz',
		'/your path to/ner/stanford-ner-2013-11-12/stanford-ner.jar'
	);
	
	//remove empty values in array & reindex
	$tmp = array_filter(explode(' ', $sentence));
	$tmp = array_values($tmp);
	//var_dump($tmp);

	$result = $pos->tag($tmp);
	$index = 0;
	for($i = 0; $i < count($result); $i ++)
	{
		$word = $result[$i][0];
		$tag = $result[$i][1];
		
		if($word == "endend")
		{
			$index++;
			$tag = "O";
		}			
		switch ($tag)
		{
			case "ORG":
				$entity[$index]['organization'][] = $word;				
				break;
			case "PERSON":
				$entity[$index]['person'][] = $word;
				break;
			case "LOC":
				$entity[$index]['location'][] = $word;
				break;
			case "GPE":
				$entity[$index]['gpe'][] = $word;
				break;
			//when case = "misc" or "O", do nothing
			default:
				break;
		}
	}

	//end of tagging
	return $entity;
}

//$type = 0, every two entities are different(freebase)
//$type = 1, in current tag type, no duplicated(news)
function noDuplicated($entity, $type)
{
	global $query;
	$noDuplicatedEntity = array();
	$currentEntity = array();
	
	for($i = 0; $i < count($entity); $i++)
	{
		//The entity array doesn't necessary have every index(0-7)
		if (array_key_exists($i,$entity))
		{
			foreach(array_keys($entity[$i]) as $property)
			{
				
				if($type == 1) $currentEntity = array();
				for($j = 0; $j < count($entity[$i][$property]); $j++)
				{								
					
					if(($entity[$i][$property][$j] != $query) && (!in_array($entity[$i][$property][$j],$currentEntity)))
					{
						if($type == 0)
						{
							$noDuplicatedEntity[$i][$property][$entity[$i][$property][$j]] = 1;
						}
						else $noDuplicatedEntity[$i][$property][] = $entity[$i][$property][$j];					
						$currentEntity[] = $entity[$i][$property][$j];
					}
					else if(in_array($entity[$i][$property][$j],$currentEntity))
					{
						if($type == 0)
						{
							$noDuplicatedEntity[$i][$property][$entity[$i][$property][$j]]++;
						}
					}
					
				}
			}
		}		
	}
	return $noDuplicatedEntity;
}

//$entity is: in current tag type, no duplicated, with title & content
function noEventInfo($entity)
{
	$noEventEntity = array();
	for($i = 0; $i < count($entity); $i ++)
	{
		foreach(array_keys($entity[$i]) as $property)
		{
			if(($property != "title") && ($property != "content") && ($property != "time"))
			{
				for($j = 0; $j < count($entity[$i][$property]); $j++)
				{
					$noEventEntity[$property][$entity[$i][$property][$j]][] = $entity[$i]["time"];
					$noEventEntity[$property][$entity[$i][$property][$j]][] = $entity[$i]["title"];
					$noEventEntity[$property][$entity[$i][$property][$j]][] = $entity[$i]["content"];
				}
			}
		}
	}
	//return $noEventEntity;
	//bubble resort depend on news time
	foreach(array_keys($noEventEntity) as $property)
	{
		foreach(array_keys($noEventEntity[$property]) as $key)
		{
			$length = count($noEventEntity[$property][$key]);			 
			for($i = 0; $i < $length; $i += 3)
			{
				for($j = $i + 3; $j < $length; $j += 3)
				{
					if( strtotime($noEventEntity[$property][$key][$i])
					  < strtotime($noEventEntity[$property][$key][$j]) )
					{
						$tmpTime = $noEventEntity[$property][$key][$i];
						$tmpTitle = $noEventEntity[$property][$key][$i+1];
						$tmpContent = $noEventEntity[$property][$key][$i+2];
						
						$noEventEntity[$property][$key][$i] = $noEventEntity[$property][$key][$j];
						$noEventEntity[$property][$key][$i+1] = $noEventEntity[$property][$key][$j+1];
						$noEventEntity[$property][$key][$i+2] = $noEventEntity[$property][$key][$j+2];
						
						$noEventEntity[$property][$key][$j] = $tmpTime;
						$noEventEntity[$property][$key][$j+1] = $tmpTitle;
						$noEventEntity[$property][$key][$j+2] = $tmpContent;
					}
				}
			}
			if($length > 9)
			{
				$noEventEntity[$property][$key] = array_slice($noEventEntity[$property][$key],0,9);
			}
		}		
	}			
	return $noEventEntity;
}


echo $_GET['callback'] . "(" . json_encode($entity) . ")";
?>
