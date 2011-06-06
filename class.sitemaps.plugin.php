<?php if (!defined('APPLICATION')) exit();
/*
Copyright 2008, 2009 Vanilla Forums Inc.
This file is part of Garden.
Garden is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
Garden is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with Garden.  If not, see <http://www.gnu.org/licenses/>.
Contact Vanilla Forums Inc. at support [at] vanillaforums [dot] com
*/

// Define the plugin:
$PluginInfo['Sitemaps'] = array(
   'Name' => 'Sitemaps',
   'Description' => "This plugin creates http://www.sitemaps.org compatible XML sitemaps for your forum.",
   'Version' => '0.9.1',
   'MobileFriendly' => TRUE,
   'RequiredApplications' => FALSE,
   'RequiredTheme' => FALSE, 
   'RequiredPlugins' => FALSE,
   'HasLocale' => TRUE,
   'RegisterPermissions' => FALSE,
   'Author' => "Tim Gunter",
   'AuthorEmail' => 'tim@vanillaforums.com',
   'AuthorUrl' => 'http://www.vanillaforums.com'
);

class SitemapsPlugin extends Gdn_Plugin {
   
   protected $Sitemaps;
   protected $Entries;
   protected $Sitemap;
   protected $Root;
   protected $Lastmod;

   public function PluginController_Sitemaps_Create(&$Sender) {
		$this->Dispatch($Sender, $Sender->RequestArgs);
   }
   
   public function Base_Render_Before(&$Sender) {
      if (($Filename = Gdn::Request()->Filename()) && $Filename != 'default') {
         $Parts = explode('.',$Filename);
         $Prefix = array_shift($Parts); $Suffix = array_pop($Parts);
         if ($Prefix == 'sitemap' && $Suffix == 'xml') {
            $this->RenderMap($Sender, $Filename);
            exit();
         }
      }
   }
   
   public function PostController_AfterDiscussionSave_Handler(&$Sender) {
      $Discussion = $Sender->EventArguments['Discussion'];
      $DiscussionPostDate = strtotime($Discussion->DateInserted);
      $DiscussionPostIndex = (int)date('Y',$DiscussionPostDate) + (int)date('d',$DiscussionPostDate);
      
      $LastDiscussionIndex = C('Plugin.Sitemaps.LastDiscussionIndex',0);
      
      if ($DiscussionPostIndex > $LastDiscussionIndex) {
         SaveToConfig('Plugin.Sitemaps.LastDiscussionIndex', $DiscussionPostIndex);
         SaveToConfig('Plugin.Sitemaps.Regenerate', TRUE);
      }
   }
   
   public function DiscussionController_BeforeDiscussionRender_Handler(&$Sender) {
      if (!C('Plugin.Sitemaps.Regenerate')) return;
      RemoveFromConfig('Plugin.Sitemaps.Regenerate');
      $Sender->AddJsFile($this->GetResource('js/sitemaps.js',FALSE,FALSE));
   }
   
   public function Controller_Build(&$Sender) {
      $Sender->DeliveryType(DELIVERY_TYPE_VIEW);
      $Sender->DeliveryMethod(DELIVERY_METHOD_XHTML);
      $this->Build();
      $Sender->Render($this->GetView('showfile.php'));
   }
   
   public function RenderMap(&$Sender, $Filename) {
      $MapDir = CombinePaths(array(PATH_CACHE,C('Plugin.Sitemaps.MapDir', 'Sitemaps')));
      if (!is_dir($MapDir)) return;
      
      $Sender->DeliveryType(DELIVERY_TYPE_VIEW);
      
      $MapFile = CombinePaths(array($MapDir,$Filename));
      if (!file_exists($MapFile) || !is_file($MapFile)) return;
      header('Content-Type: text/xml');
      readfile($MapFile);
   }
   
   protected function Build() {
      $DiscussionModel = new DiscussionModel();
      
      $Offset = 0; $Limit = 1000;
      while ($Discussions = $DiscussionModel->Get($Offset, $Limit)) {
         if (!$Discussions->NumRows()) break;
         $Offset += $Discussions->NumRows();
         
         $Day = 24*3600; $Week = 7*$Day; $Month = 4*$Week; $Year = 12*$Month;
         $PriorityMatrix = array(
            'hourly'    => 1,
            'daily'     => 0.8,
            'weekly'    => 0.6,
            'monthly'   => 0.4,
            'yearly'    => 0.2
         );
         while ($Discussion = $Discussions->NextRow()) {
            $ChangeFreq = 'hourly';
            $DiffDate = time() - strtotime($Discussion->DateLastComment);
            $Priority = 1;
            
            if ($DiffDate < $Day)
               $ChangeFreq = 'hourly';
            elseif ($DiffDate < $Week)
               $ChangeFreq = 'daily';
            elseif ($DiffDate < $Month)
               $ChangeFreq = 'weekly';
            elseif ($DiffDate < $Year)
               $ChangeFreq = 'monthly';
            else
               $ChangeFreq = 'yearly';
               
            $this->MapItem(
               DiscussionLink($Discussion, FALSE),
               date('Y-m-d', strtotime($Discussion->DateLastComment)),
               $ChangeFreq,
               $PriorityMatrix[$ChangeFreq]
            );
         }
      }
      
      $this->WriteIndex();
   }
   
   protected function NewSitemap($CloseOnly = FALSE) {
      $this->CloseSitemap();
      
      $Document = $this->CreateDocument('urlset', 'http://www.sitemaps.org/schemas/sitemap/0.9');
      $this->Root = $Document->Root;
      $this->Sitemap = $Document;
      $this->Lastmod = NULL;
      $this->Entries = 0;
      
      return TRUE;
   }
   
   protected function CloseSitemap() {
      if (!is_null($this->Sitemap)) {
         
         $MapDir = CombinePaths(array(PATH_CACHE,C('Plugin.Sitemaps.MapDir', 'Sitemaps')));
         if (!is_dir($MapDir)) mkdir($MapDir);
         if (!is_dir($MapDir)) return;
      
         $MapUnique = uniqid().'-'.microtime(true).'-'.mt_rand().'-'.mt_rand();
         $MapHash = sha1($MapUnique);
         $MapName = "sitemap.{$MapHash}.xml";
         $MapFile = CombinePaths(array($MapDir, $MapName));
         $this->Sitemap->save($MapFile);
         $this->Sitemap = NULL;
         $this->Sitemaps[] = array(
            'loc'       => Url(basename($MapFile),TRUE),
            'file'      => basename($MapFile),
            'hash'      => $MapHash,
            'lastmod'   => (!is_null($this->Lastmod)) ? $this->Lastmod : date('Y-m-d')
         );
      }
   }
   
   protected function Ready() {
      if (is_null($this->Sitemap)) return FALSE;
      if ($this->Entries > 45000) return FALSE;
      
      return TRUE;
   }
   
   protected function WriteIndex() {
      $this->CloseSitemap();
      
      $Document = $this->CreateDocument('sitemapindex', 'http://www.sitemaps.org/schemas/sitemap/0.9');
      $Root = $Document->Root;
      
      $MapFiles = array();
      foreach ($this->Sitemaps as $SitemapData) {
         $Sitemap = $Document->createElement('sitemap');
         $MapFiles[] = $SitemapData['file'];
         
         if ($SitemapData['loc']) {
            $Item = $Document->createElement('loc');
            $Item->appendChild($Document->createTextNode($SitemapData['loc'])); 
            $Sitemap->appendChild($Item);
         }
         
         if ($SitemapData['lastmod']) {
            $Item = $Document->createElement('lastmod');
            $Item->appendChild($Document->createTextNode($SitemapData['lastmod'])); 
            $Sitemap->appendChild($Item);
         }
         
         $Root->appendChild($Sitemap);
      }
      $MapFiles = array_flip($MapFiles);
      
      $MapDir = CombinePaths(array(PATH_CACHE,C('Plugin.Sitemaps.MapDir', 'Sitemaps')));
      if (!is_dir($MapDir)) mkdir($MapDir);
      if (!is_dir($MapDir)) return;
   
      $IndexName = 'sitemap.index';
      $IndexFile = CombinePaths(array($MapDir, $IndexName.'.xml'));
      
      $MapDirScan = scandir($MapDir);
      if (is_array($MapDirScan)) {
         foreach ($MapDirScan as $MapDirFile) {
            if (in_array($MapDirFile,array('.','..'))) continue;
            if (!array_key_exists($MapDirFile, $MapFiles))
               unlink(CombinePaths(array($MapDir, $MapDirFile)));
         }
      }
      $Document->save($IndexFile);
   }
   
   protected function MapItem($Url, $Lastmod = NULL, $ChangeFreq = NULL, $Priority = NULL) {
      $Entry = array('loc' => $Url);
      
      if (!is_null($Lastmod))
         $Entry['lastmod'] = $Lastmod;
      if (!is_null($ChangeFreq))
         $Entry['changefreq'] = $ChangeFreq;
      if (!is_null($Priority))
         $Entry['priority'] = $Priority;
      
      if (!$this->Ready())
         $this->NewSitemap();
         
      $this->Entries++;
      if (is_null($this->Lastmod) || (!is_null($Lastmod) && strtotime($Lastmod) > strtotime($this->Lastmod))) {
         $this->Lastmod = $Lastmod;
      }
      
      $Url = $this->Sitemap->createElement('url');
      
      foreach ($Entry as $EntryItem => $ItemValue) {
         $Item = $this->Sitemap->createElement($EntryItem);
         $Item->appendChild($this->Sitemap->createTextNode($ItemValue)); 
         $Url->appendChild($Item);
      }
      $this->Root->appendChild($Url);

   }
   
   protected function CreateDocument($RootNode, $RootNamespace) {
      $Document = new DOMDocument();
      $Document->preserveWhiteSpace = false;
      $Document->formatOutput = true; 
      $Document->encoding = 'UTF-8';
      $Document->xmlVersion = '1.0';
      
      // Create root 'urlset' element
      $Root = $Document->createElement($RootNode);
      $SitemapNS = $Document->createAttribute('xmlns');
      $SitemapNS->appendChild($Document->createTextNode($RootNamespace));
      $Root->appendChild($SitemapNS);
      $Document->appendChild($Root);
      $Document->Root = $Root;
      
      return $Document;
   }
   
   public function Setup() {
      // Nothing to do here!
   }
   
   public function Structure() {
      // Nothing to do here!
   }
         
}