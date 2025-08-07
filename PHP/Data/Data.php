<?php
/**
 * Project: CynFox Data Library
 * File: Data.php
 * Created: 2025-07-31
 * Author: LiMingQi
 * Copyright: (c) 2025 CynFox. All rights reserved.
 * License: MIT License
 * Description: Provides Data Handling Functionalities for The CynFox System.
 *  This Class is Used to Read and Write Data Files From Specified Paths, Supporting Memory Caching and Content Format Parsing.
 *  Provide Common Functions Such as Search, Update, and Save.
 * 
 * 项目名称: CynFox 数据处理库
 * 文件名称: Data.php
 * 创建时间: 2025-07-31
 * 创建者: 李明琪
 * 版权所有: (c) 2025 网狐. 保留所有权利.
 * 许可协议: MIT License
 * 描述：为 CynFox 系统提供数据处理功能。
 *  该类用于从指定路径读取和写入数据文件，支持内存缓存和内容格式解析。
 *  提供搜索、更新、保存等常用功能。
 */

declare(strict_types = 1); // 要求 PHP 严格审查参数类型

/**
 * Data 类 - 基于文件系统的数据管理类
 * 
 * 功能特性：
 * - 文件系统数据存储，支持多种格式（键值对、列表、JSON）
 * - 多级缓存系统（类型缓存 + 内存缓存 + 时间戳验证）
 * - 安全路径处理，防止路径遍历攻击
 * - 智能数据格式检测和转换
 * - 高级搜索功能（键值搜索、精确/模糊匹配）
 * - 并发安全的文件操作
 * - 详细的错误处理和日志记录
 * 
 * @author LiMingQi
 * @version 1.0
 * @since 2025-07-31
 */
class Data 
{
	/**
	 * 数据根目录路径
	 * 所有数据文件都存储在此目录下
	 * @var string
	 */
	private string $Root;
	
	/**
	 * 类型缓存 - 存储每个对象的数据结构类型
	 * 格式: ['对象路径' => '数据类型']
	 * 数据类型: 'KeyValue', 'List', 'JSON'
	 * @var array
	 */
	private array $TypeCache;
	
	/**
	 * 内存缓存 - 存储解析后的数据内容
	 * 格式: ['对象路径' => ['Data' => 数据内容, 'Timestamp' => 文件修改时间]]
	 * @var array
	 */
	private array $MemCache;
	
	/**
	 * 缓存有效期（秒）
	 * 超过此时间的缓存将被视为过期
	 * @var int
	 */
	private int $CacheExpiry;
	
	/**
	 * 是否启用调试模式
	 * 调试模式下会记录详细的操作日志
	 * @var bool
	 */
	private bool $DebugMode;
	
	/**
	 * 支持的文件扩展名
	 * @var array
	 */
	private array $SupportedExtensions;

	/**
	 * 构造函数 - 初始化数据存储系统
	 * 
	 * @param string $RootPath 数据根目录路径，默认为 './Data'
	 * @param int $CacheExpiry 缓存有效期（秒），默认为 300 秒（5分钟）
	 * @param bool $DebugMode 是否启用调试模式，默认关闭
	 * 
	 * @throws Exception 当根目录创建失败时抛出异常
	 */
	public function __construct(string $RootPath = './Data', int $CacheExpiry = 300, bool $DebugMode = false)
	{
		// 标准化根目录路径
		$this->Root = rtrim($RootPath, '/\\') . DIRECTORY_SEPARATOR;
		
		// 初始化缓存系统
		$this->TypeCache = [];
		$this->MemCache = [];
		$this->CacheExpiry = max(60, $CacheExpiry); // 最小缓存时间 60 秒
		
		// 设置调试模式
		$this->DebugMode = $DebugMode;
		
		// 支持的文件扩展名
		$this->SupportedExtensions = ['.data', '.json', '.txt'];
		
		// 确保根目录存在
		if (!is_dir($this->Root))
		{
			if (!mkdir($this->Root, 0755, true))
			{
				throw new Exception("无法创建数据根目录: {$this->Root}");
			};
			$this->DebugLog("创建数据根目录: {$this->Root}");
		};
		
		$this->DebugLog("Data 类初始化完成，根目录: {$this->Root}，缓存有效期: {$this->CacheExpiry} 秒");
	}

	/**
	 * 函数 Get - 获取对象数据
	 * 
	 * @param string $Object 对象路径，支持嵌套路径（如 'User/Admin'）
	 * @return array 返回解析的数据数组，失败时返回空数组
	 * 
	 * @throws Exception 当文件读取失败时抛出异常
	 */
	public function Get(string $Object): array
	{
		try
		{
			// 清理和验证对象路径
			$SanitizedPath = $this->SanitizeName($Object);
			$FilePath = $this->Root . $SanitizedPath . '.data';
			
			$this->DebugLog("尝试获取对象数据: $Object -> $FilePath");

			// 验证路径安全性
			if (!$this->ValidatePath($FilePath))
			{
				$this->DebugLog("路径验证失败: $FilePath");
				return [];
			};

			// 检查文件是否存在
			if (!file_exists($FilePath))
			{
				$this->DebugLog("文件不存在: $FilePath");
				return [];
			};

			// 获取文件修改时间
			$FileModTime = filemtime($FilePath);
			if ($FileModTime === false)
			{
				$this->DebugLog("无法获取文件修改时间: $FilePath");
				return [];
			};

			// 检查内存缓存是否存在且有效
			if (isset($this->MemCache[$Object]))
			{
				$CacheData = $this->MemCache[$Object];
				$CacheTime = $CacheData['timestamp'] ?? 0;
				$CacheAge = time() - $CacheTime;
				
				// 验证缓存时间戳和有效期
				if ($CacheData['file_mtime'] === $FileModTime && $CacheAge < $this->CacheExpiry)
				{
					$this->DebugLog("使用有效缓存数据: $Object (缓存年龄: {$CacheAge}s)");
					return $CacheData['data'];
				}
				else
				{
					$this->DebugLog("缓存已过期或文件已更新: $Object (缓存年龄: {$CacheAge}s, 文件时间: $FileModTime vs {$CacheData['FileModTime']})");
				};
			};

			// 读取文件内容
			$Content = file_get_contents($FilePath);
			if ($Content === false)
			{
				throw new Exception("无法读取文件: $FilePath");
			};

			// 解析文件内容
			$ParsedData = $this->ParseContent($Content);
			
			// 更新内存缓存
			$this->MemCache[$Object] = [
				'Data' => $ParsedData,
				'Timestamp' => time(),
				'FileModTime' => $FileModTime
			];
			
			$this->DebugLog("成功读取并缓存数据: $Object (" . count($ParsedData) . " 项)");
			
			return $ParsedData;
		}
		catch (Exception $Error)
		{
			$this->DebugLog("获取数据时发生错误: " . $Error->getMessage());
			throw $Error;
		};
	}

	/**
	 * 函数 Save - 保存对象数据
	 * 
	 * @param string $Object 对象路径
	 * @param array $Data 要保存的数据数组
	 * @param bool $Overwrite 是否覆盖现有数据，默认 false（合并模式）
	 * 
	 * @throws Exception 当保存失败时抛出异常
	 */
	public function Save(string $Object, array $Data, bool $Overwrite = false): void
	{
		try
		{
			// 验证输入参数
			if (empty($Object))
			{
				throw new Exception("对象路径不能为空");
			};

			// 清理和验证路径
			$SanitizedPath = $this->SanitizeName($Object);
			$FilePath = $this->Root . $SanitizedPath . '.data';
			$DirPath = dirname($FilePath);

			$this->DebugLog("尝试保存对象数据: $Object -> $FilePath (覆盖模式: " . ($Overwrite ? '是' : '否') . ")");

			// 验证路径安全性
			if (!$this->ValidatePath($FilePath))
			{
				throw new Exception("路径验证失败: $FilePath");
			};

			// 创建目录结构
			if (!is_dir($DirPath))
			{
				if (!mkdir($DirPath, 0755, true))
				{
					throw new Exception("无法创建目录: $DirPath");
				}
				$this->DebugLog("创建目录: $DirPath");
			};

			// 处理数据合并
			$FinalData = $Data;
			if (!$Overwrite && file_exists($FilePath))
			{
				$ExistingData = $this->Get($Object);
				$FinalData = array_merge($ExistingData, $Data);
				$this->DebugLog("合并现有数据: " . count($ExistingData) . " + " . count($Data) . " = " . count($FinalData) . " 项");
			};

			// 分析数据结构并构建内容
			$DataType = $this->AnalyzeStructure($FinalData);
			$this->TypeCache[$Object] = $DataType;

			$Content = '';
			switch ($DataType)
			{
				case 'KeyValue':
					$Content = $this->BuildKeyValue($FinalData);
					break;
				case 'List':
					$Content = $this->BuildList($FinalData);
					break;
				default:
					$Content = $this->Stringify($FinalData);
					break;
			};

			// 写入文件（使用文件锁防止并发问题）
			$BytesWritten = file_put_contents($FilePath, $Content, LOCK_EX);
			if ($BytesWritten === false)
			{
				throw new Exception("无法写入文件: $FilePath");
			};
			
			// 更新内存缓存
			$FileModTime = filemtime($FilePath);
			$this->MemCache[$Object] = [
				'Data' => $FinalData,
				'Timestamp' => time(),
				'FileModTime' => $FileModTime
			];
			
			$this->DebugLog("成功保存数据: $Object ($BytesWritten 字节, 类型: $DataType)");
		}
		catch (Exception $Error)
		{
			$this->DebugLog("保存数据时发生错误: " . $Error->getMessage());
			throw $Error;
		};
	}

	/**
	 * 函数 Update - 更新对象数据
	 * 
	 * @param string $Object 对象路径
	 * @param callable $Modifier 修改器函数，接收当前数据数组，返回修改后的数据数组
	 * 
	 * @throws Exception 当更新失败时抛出异常
	 */
	public function Update(string $Object, callable $Modifier): void
	{
		try
		{
			$this->DebugLog("尝试更新对象数据: $Object");
			
			// 获取当前数据
			$CurrentData = $this->Get($Object);
			
			// 调用修改器函数
			$ModifiedData = $Modifier($CurrentData);
			
			// 验证修改结果
			if (!is_array($ModifiedData))
			{
				throw new Exception("修改器函数必须返回数组类型");
			};
			
			// 保存修改后的数据
			$this->Save($Object, $ModifiedData, true);
			
			$this->DebugLog("成功更新对象数据: $Object");
		}
		catch (Exception $Error)
		{
			$this->DebugLog("更新数据时发生错误: " . $Error->getMessage());
			throw $Error;
		};
	}

	/**
	 * 函数 Search - 搜索对象数据
	 * 
	 * @param string $Object 对象路径
	 * @param string $SearchTerm 搜索词，不能为空
	 * @param bool $ExactMatch 是否精确匹配，默认 false（模糊匹配）
	 * @param string $Type 搜索类型：'Key'（键名）、'Value'（值）、'Both'（键名和值）
	 * @return array 搜索结果数组，格式与原数据相同
	 * 
	 * @throws Exception 当搜索参数无效时抛出异常
	 */
	public function Search(string $Object, string $SearchTerm, bool $ExactMatch = false, string $Type = "Key"): array
	{
		try
		{
			// 验证搜索参数
			if (empty($SearchTerm))
			{
				throw new Exception("搜索词不能为空");
			};

			$ValidTypes = ['Key', 'Value', 'Both'];
			if (!in_array($Type, $ValidTypes))
			{
				throw new Exception("无效的搜索类型: $Type，支持的类型: " . implode(', ', $ValidTypes));
			};

			$this->DebugLog("开始搜索: $Object，搜索词: '$SearchTerm'，类型: $Type，精确匹配: " . ($ExactMatch ? '是' : '否'));

			// 获取数据
			$Data = $this->Get($Object);
			if (empty($Data))
			{
				$this->DebugLog("搜索对象为空: $Object");
				return [];
			};

			$Results = [];
			$MatchCount = 0;

			// 遍历数据进行搜索
			foreach ($Data as $Key => $Value)
			{
				$KeyMatch = false;
				$ValueMatch = false;

				// 检查键名匹配
				if ($Type === 'Key' || $Type === 'Both')
				{
					$KeyStr = (string)$Key;
					$KeyMatch = $ExactMatch ? 
						($KeyStr === $SearchTerm) : 
						(stripos($KeyStr, $SearchTerm) !== false);
				};

				// 检查值匹配
				if ($Type === 'Value' || $Type === 'Both')
				{
					$ValueMatch = $this->SearchInValue($Value, $SearchTerm, $ExactMatch);
				};

				// 添加匹配结果
				if ($KeyMatch || $ValueMatch)
				{
					$Results[$Key] = $Value;
					$MatchCount++;
				};
			};

			$this->DebugLog("搜索完成: 找到 $MatchCount 个匹配项");
			
			return $Results;
		}
		catch (Exception $Error)
		{
			$this->DebugLog("搜索时发生错误: " . $Error->getMessage());
			throw $Error;
		};
	}

	/**
	 * 函数 Delete - 删除对象数据
	 * 
	 * @param string $Object 对象路径
	 * @return bool 删除是否成功
	 * 
	 * @throws Exception 当删除失败时抛出异常
	 */
	public function Delete(string $Object): bool
	{
		try
		{
			$SanitizedPath = $this->SanitizeName($Object);
			$FilePath = $this->Root . $SanitizedPath . '.data';

			$this->DebugLog("尝试删除对象: $Object -> $FilePath");

			// 验证路径安全性
			if (!$this->ValidatePath($FilePath))
			{
				throw new Exception("路径验证失败: $FilePath");
			};

			// 删除文件
			if (file_exists($FilePath))
			{
				if (!unlink($FilePath))
				{
					throw new Exception("无法删除文件: $FilePath");
				};
			};

			// 清除缓存
			unset($this->MemCache[$Object]);
			unset($this->TypeCache[$Object]);

			$this->DebugLog("成功删除对象: $Object");
			return true;
		}
		catch (Exception $Error)
		{
			$this->DebugLog("删除对象时发生错误: " . $Error->getMessage());
			throw $Error;
		};
	}

	/**
	 * 函数 Exists - 检查对象是否存在
	 * 
	 * @param string $Object 对象路径
	 * @return bool 对象是否存在
	 */
	public function Exists(string $Object): bool
	{
		$SanitizedPath = $this->SanitizeName($Object);
		$FilePath = $this->Root . $SanitizedPath . '.data';
		
		return $this->ValidatePath($FilePath) && file_exists($FilePath);
	}

	/**
	 * 函数 GetCacheStats - 获取缓存统计信息
	 * 
	 * @return array 缓存统计信息
	 */
	public function GetCacheStats(): array
	{
		$Stats = [
			'MemCacheCount' => count($this->MemCache),
			'TypeCacheCount' => count($this->TypeCache),
			'CacheExpiry' => $this->CacheExpiry,
			'ExpiredCacheCount' => 0,
			'ValidCacheCount' => 0
		];

		$CurrentTime = time();
		foreach ($this->MemCache as $CacheData)
		{
			$CacheAge = $CurrentTime - ($CacheData['Timestamp'] ?? 0);
			if ($CacheAge >= $this->CacheExpiry)
			{
				$Stats['ExpiredCacheCount']++;
			}
			else
			{
				$Stats['ValidCacheCount']++;
			};
		};

		return $Stats;
	}

	/**
	 * 函数 CleanExpiredCache - 清理过期缓存
	 * 
	 * @return int 清理的缓存数量
	 */
	public function CleanExpiredCache(): int
	{
		$CleanedCount = 0;
		$CurrentTime = time();

		foreach ($this->MemCache as $Object => $CacheData)
		{
			$CacheAge = $CurrentTime - ($CacheData['timestamp'] ?? 0);
			if ($CacheAge >= $this->CacheExpiry)
			{
				unset($this->MemCache[$Object]);
				$CleanedCount++;
			};
		};

		$this->DebugLog("清理过期缓存: $CleanedCount 项");
		return $CleanedCount;
	}

	/**
	 * 函数 SanitizeName - 清理对象名称
	 * 
	 * @param string $Name 原始名称
	 * @return string 清理后的安全名称
	 */
	private function SanitizeName(string $Name): string
	{
		// 移除危险字符，保留安全字符
		$Sanitized = preg_replace('/[^a-zA-Z0-9\/\\\_\-\.]/', '', $Name);
		
		// 标准化路径分隔符
		$Sanitized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $Sanitized);
		
		// 移除连续的分隔符
		$Sanitized = preg_replace('/[\\' . DIRECTORY_SEPARATOR . ']+/', DIRECTORY_SEPARATOR, $Sanitized);
		
		// 移除开头和结尾的分隔符
		$Sanitized = trim($Sanitized, DIRECTORY_SEPARATOR);
		
		// 防止路径遍历攻击
		$Sanitized = str_replace('..', '', $Sanitized);
		
		return $Sanitized;
	}

	/**
	 * 函数 ValidatePath - 验证文件路径安全性
	 * 
	 * @param string $Path 要验证的文件路径
	 * @return bool 路径是否安全有效
	 */
	private function ValidatePath(string $Path): bool
	{
		// 获取真实路径
		$RealRoot = realpath($this->Root);
		if ($RealRoot === false)
		{
			return false;
		};

		// 获取目标目录的真实路径
		$TargetDir = dirname($Path);
		if (!is_dir($TargetDir))
		{
			// 如果目录不存在，检查父目录
			$TargetDir = $this->Root;
		};

		$RealPath = realpath($TargetDir);
		if ($RealPath === false)
		{
			return false;
		};
		
		// 检查路径是否在根目录内
		return strpos($RealPath, $RealRoot) === 0;
	}

	/**
	 * 函数 DetectListType - 检测数据是否为列表类型
	 * 
	 * @param array $Data 要检测的数据数组
	 * @return bool 是否为列表类型
	 */
	private function DetectListType(array $Data): bool
	{
		if (empty($Data))
		{
			return false;
		};

		$Keys = array_keys($Data);
		$NumericKeys = array_filter($Keys, 'is_numeric');
		
		// 检查所有键都是数字且连续
		return count($NumericKeys) === count($Keys) && 
			   $Keys === range(0, count($Keys) - 1);
	}

	/**
	 * 函数 AnalyzeStructure - 分析数据结构类型
	 * 
	 * @param array $Data 要分析的数据数组
	 * @return string 数据结构类型
	 */
	private function AnalyzeStructure(array $Data): string
	{
		if (empty($Data))
		{
			return 'KeyValue';
		};

		// 检测是否为列表类型
		if ($this->DetectListType($Data))
		{
			return 'List';
		};

		// 检测是否包含复杂嵌套结构
		foreach ($Data as $Value)
		{
			if (is_array($Value) && !empty($Value))
			{
				return 'JSON';
			};
		};

		// 默认为键值对类型
		return 'KeyValue';
	}

	/**
	 * 函数 ParseContent - 解析文件内容
	 * 
	 * @param string $Content 文件内容
	 * @return array 解析后的数据数组
	 */
	private function ParseContent(string $Content): array
	{
		$Content = trim($Content);
		
		if (empty($Content))
		{
			return [];
		};

		// 尝试 JSON 解析
		$JsonData = json_decode($Content, true);
		if ($JsonData !== null && json_last_error() === JSON_ERROR_NONE)
		{
			return is_array($JsonData) ? $JsonData : [$JsonData];
		};

		// 按行分割内容
		$Lines = explode("\n", $Content);
		$Lines = array_map('trim', $Lines);
		$Lines = array_filter($Lines, function($line) { return $line !== ''; });
		
		if (empty($Lines))
		{
			return [];
		};

		// 检测格式类型
		$FirstLine = $Lines[0];
		
		// 键值对格式检测
		if (strpos($FirstLine, '=') !== false)
		{
			return $this->ParseKeyValue($Lines);
		};
		
		// 列表格式检测
		if (strpos($FirstLine, '-') === 0)
		{
			return $this->ParseList($Lines);
		};

		// 默认作为简单列表处理
		return array_values($Lines);
	}

	/**
	 * 函数 ParseKeyValue - 解析键值对格式
	 * 
	 * @param array $Lines 文件行数组
	 * @return array 解析后的关联数组
	 */
	private function ParseKeyValue(array $Lines): array
	{
		$Result = [];
		
		foreach ($Lines as $LineNumber => $Line)
		{
			// 跳过注释行和空行
			if (empty($Line) || strpos($Line, '#') === 0 || strpos($Line, '//') === 0)
			{
				continue;
			};

			// 查找等号分隔符
			$EqualPos = strpos($Line, '=');
			if ($EqualPos === false)
			{
				$this->DebugLog("跳过无效的键值对行 $LineNumber: $Line");
				continue;
			};

			// 分割键和值
			$Key = trim(substr($Line, 0, $EqualPos));
			$Value = trim(substr($Line, $EqualPos + 1));
			
			if (empty($Key))
			{
				$this->DebugLog("跳过空键名行 $LineNumber: $Line");
				continue;
			};
			
			$Result[$Key] = $this->ConvertValue($Value);
		};

		return $Result;
	}

	/**
	 * 函数 ParseList - 解析列表格式
	 * 
	 * @param array $Lines 文件行数组
	 * @return array 解析后的索引数组
	 */
	private function ParseList(array $Lines): array
	{
		$Result = [];
		
		foreach ($Lines as $LineNumber => $Line)
		{
			// 跳过注释行和空行
			if (empty($Line) || strpos($Line, '#') === 0 || strpos($Line, '//') === 0)
			{
				continue;
			};

			// 检查列表项标记
			if (strpos($Line, '-') !== 0)
			{
				$this->DebugLog("跳过无效的列表项行 $LineNumber: $Line");
				continue;
			};

			// 提取列表项值
			$Value = trim(substr($Line, 1));
			if (!empty($Value))
			{
				$Result[] = $this->ConvertValue($Value);
			};
		};

		return $Result;
	}

	/**
	 * 函数 BuildKeyValue - 构建键值对格式内容
	 * 
	 * @param array $Data 数据数组
	 * @return string 构建的文件内容
	 */
	private function BuildKeyValue(array $Data): string
	{
		$Lines = [];
		
		foreach ($Data as $Key => $Value)
		{
			$ValueStr = $this->Stringify($Value);
			$Lines[] = "$Key=$ValueStr";
		};

		return implode("\n", $Lines);
	}

	/**
	 * 函数 BuildList - 构建列表格式内容
	 * 
	 * @param array $Data 数据数组
	 * @return string 构建的文件内容
	 */
	private function BuildList(array $Data): string
	{
		$Lines = [];
		
		foreach ($Data as $Value)
		{
			$ValueStr = $this->Stringify($Value);
			$Lines[] = "- $ValueStr";
		};

		return implode("\n", $Lines);
	}

	/**
	 * 函数 ConvertValue - 转换字符串值为适当的数据类型
	 * 
	 * @param string $Value 要转换的字符串值
	 * @return mixed 转换后的值
	 */
	private function ConvertValue(string $Value): mixed
	{
		// 处理空值
		if ($Value === '')
		{
			return '';
		};

		// 尝试 JSON 解析
		if ((strpos($Value, '{') === 0 && strrpos($Value, '}') === strlen($Value) - 1) ||
			(strpos($Value, '[') === 0 && strrpos($Value, ']') === strlen($Value) - 1))
		{
			$JsonValue = json_decode($Value, true);
			if ($JsonValue !== null && json_last_error() === JSON_ERROR_NONE)
			{
				return $JsonValue;
			};
		};

		// 布尔值转换
		$LowerValue = strtolower($Value);
		if ($LowerValue === 'true')
		{
			return true;
		};
		if ($LowerValue === 'false')
		{
			return false;
		};

		// null 值转换
		if ($LowerValue === 'null')
		{
			return null;
		};

		// 数字转换
		if (is_numeric($Value))
		{
			return strpos($Value, '.') !== false ? (float)$Value : (int)$Value;
		};

		// 返回原字符串
		return $Value;
	}

	/**
	 * 函数 Stringify - 将值转换为字符串表示
	 * 
	 * @param mixed $Value 要转换的值
	 * @return string 字符串表示
	 */
	private function Stringify(mixed $Value): string
	{
		if (is_array($Value))
		{
			return json_encode($Value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		elseif (is_bool($Value))
		{
			return $Value ? 'true' : 'false';
		}
		elseif (is_null($Value))
		{
			return 'null';
		}
		else
		{
			return (string)$Value;
		};
	}

	/**
	 * 函数 SearchInValue - 在值中搜索
	 * 
	 * @param mixed $Value 要搜索的值
	 * @param string $SearchTerm 搜索词
	 * @param bool $ExactMatch 是否精确匹配
	 * @return bool 是否找到匹配
	 */
	private function SearchInValue(mixed $Value, string $SearchTerm, bool $ExactMatch): bool
	{
		// 处理数组类型
		if (is_array($Value))
		{
			// 递归搜索数组中的每个元素
			foreach ($Value as $SubValue)
			{
				if ($this->SearchInValue($SubValue, $SearchTerm, $ExactMatch))
				{
					return true;
				};
			};
			return false;
		};

		// 转换为字符串进行搜索
		$ValueStr = $this->Stringify($Value);
		
		return $ExactMatch ? 
			($ValueStr === $SearchTerm) : 
			(stripos($ValueStr, $SearchTerm) !== false);
	}

	/**
	 * 函数 DebugLog - 调试日志记录
	 * 
	 * @param string $Message 日志消息
	 */
	private function DebugLog(string $Message): void
	{
		if ($this->DebugMode)
		{
			$Timestamp = date('Y-m-d H:i:s');
			error_log("[$Timestamp] Data Class: $Message");
		};
	}
};
?>
