<?php

namespace Grocy\Services;

use LessQL\Result;

class TasksService extends BaseService
{
	public function GetCurrent(): Result
	{
		$users = UsersService::GetInstance()->GetUsersAsDto();
		$categories = $this->DB->task_categories()->where('active = 1');

		$tasks = $this->DB->tasks_current();
		foreach ($tasks as $task)
		{
			if (!empty($task->assigned_to_user_id))
			{
				$task->assigned_to_user = FindObjectInArrayByPropertyValue($users, 'id', $task->assigned_to_user_id);
			}
			else
			{
				$task->assigned_to_user = null;
			}

			if (!empty($task->category_id))
			{
				$task->category = FindObjectInArrayByPropertyValue($categories, 'id', $task->category_id);
			}
			else
			{
				$task->category = null;
			}
		}

		return $tasks;
	}

	public function MarkTaskAsCompleted($taskId, $doneTime)
	{
		if (!$this->TaskExists($taskId))
		{
			throw new \Exception('Task does not exist');
		}

		$taskRow = $this->DB->tasks()->where('id = :1', $taskId)->fetch();
		$taskRow->update([
			'done' => 1,
			'done_timestamp' => $doneTime
		]);

		return true;
	}

	public function UndoTask($taskId)
	{
		if (!$this->TaskExists($taskId))
		{
			throw new \Exception('Task does not exist');
		}

		$taskRow = $this->DB->tasks()->where('id = :1', $taskId)->fetch();
		$taskRow->update([
			'done' => 0,
			'done_timestamp' => null
		]);

		return true;
	}

	private function TaskExists($taskId)
	{
		$taskRow = $this->DB->tasks()->where('id = :1', $taskId)->fetch();
		return $taskRow !== null;
	}
}
