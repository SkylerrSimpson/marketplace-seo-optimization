@extends('errors.layout')
@section('code', '403')
@section('title', __('Not allowed'))
@section('message', $exception?->getMessage() ?: __('You don’t have permission to do that.'))
