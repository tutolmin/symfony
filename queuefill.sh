#!/usr/bin/bash

types=(1-0 0-1)
depths=(fast deep)

random_side () {

  if [[ "$type" == "1-0" ]]; then
    side="WhiteSide"
  else
    side="BlackSide"
  fi
}

random_depth () {

  size=${#depths[*]}
  key=$((RANDOM % $size))

  depth=${depths[$key]}
}

random_type () {

  size=${#types[*]}
  key=$((RANDOM % $size))

  type=${types[$key]}

  random_side
}

queue_fill () {

  random_type
  random_depth

  number=$((15 + RANDOM % 10))

  php bin/console queue:fill --threshold=$number --side=$side --type=$type --depth=$depth
}

# Fill the queue with some items
queue_fill
