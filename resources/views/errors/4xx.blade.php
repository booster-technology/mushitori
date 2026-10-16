{{-- 個別の画面を用意していない 4xx エラー(405 など)の共通画面。英語の標準画面が表示されないようにする --}}
@extends('errors.layout')

@section('code', $exception->getStatusCode())
@section('title', 'ページを表示できません')
@section('message', 'URLが間違っているか、この操作はできません。前の画面に戻って、もう一度お試しください。')
